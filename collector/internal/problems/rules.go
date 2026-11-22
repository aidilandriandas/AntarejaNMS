// Package problems menilai kondisi perangkat menjadi "problem" (down, latensi, loss, SNMP, port dipantau),
// menandai problem yang terdampak maintenance atau induk yang down, dan menyelaraskannya ke database.
package problems

import (
	"fmt"
	"math"
	"strings"
)

// Severity dan urutannya (dipakai juga oleh notifikasi).
var SeverityRank = map[string]int{"info": 0, "warning": 1, "average": 2, "high": 3, "disaster": 4}

type Thresholds struct {
	LatencyMS       float64
	LossPct         float64
	UtilPct         float64
	SNMPFailCycles  int
	ConflictChanges int // pergantian MAC minimal dalam jendela agar dianggap konflik IP
	ConflictHours   int
}

// Device adalah data satu perangkat yang dibutuhkan untuk penilaian.
type Device struct {
	ID          int64
	Name        string
	Category    string
	Status      string
	Reason      string
	FailStreak  int   // putaran ping gagal total berturut-turut (sedang menuju down)
	ParentID    int64 // 0 = tanpa induk
	PingEnabled bool
	SNMPEnabled bool
	// Dari memori collector; Known=false artinya belum cukup data sejak collector start.
	PingKnown     bool
	RTTAvg        float64 // NaN bila tidak ada balasan
	LossAvg       float64
	SNMPKnown     bool
	SNMPFails     int
	InMaintenance string // judul maintenance aktif, kosong bila tidak
	Ports         []Port
	Sensors       []Sensor
	Conflicts     []Conflict // dari tabel ARP yang dilaporkan perangkat ini (router)
	Cameras       []CamHealth
}

// CamHealth adalah hasil cek gambar kamera yang terkait perangkat ini (NVR / kamera).
type CamHealth struct {
	ID           int64
	Name, Health string
	Detail       string
	Fresh        bool
}

// Conflict: satu IP yang berganti-ganti MAC pada router & port yang sama dalam jendela waktu.
type Conflict struct {
	IP      string
	MACs    []string
	Changes int
}

// Sensor adalah pembacaan sensor tersimpan (lihat profiles). Fresh=false bila tidak diperbarui > 20 menit.
type Sensor struct {
	Key, Label, Unit, Text, Status string
	Value                          *float64
	Fresh                          bool
}

type Port struct {
	Index     int
	Name      string
	AdminUp   bool
	OperUp    bool
	UtilKnown bool
	Util      float64
}

// Cond adalah satu kondisi problem yang sedang terjadi.
type Cond struct {
	DeviceID   int64
	Kind       string
	IfIndex    *int
	Severity   string
	Title      string
	Detail     string
	Value      *float64
	Suppressed string // "", "maintenance", "parent"
	Sensor     string // kunci sensor untuk problem jenis "sensor"
	SupNote    string
}

// Key unik kondisi (sama dengan indeks unik problem terbuka di database).
func (c Cond) Key() string {
	if c.Sensor != "" {
		return SensorKey(c.DeviceID, c.Sensor)
	}
	return key(c.DeviceID, c.Kind, c.IfIndex)
}

// SensorKey adalah kunci unik problem sensor.
func SensorKey(dev int64, sensor string) string { return fmt.Sprintf("%d/sensor/%s", dev, sensor) }

func key(dev int64, kind string, idx *int) string {
	i := -1
	if idx != nil {
		i = *idx
	}
	return fmt.Sprintf("%d/%s/%d", dev, kind, i)
}

// Unknown adalah kondisi yang belum bisa dinilai (data belum cukup): problem terbuka dengan kunci ini dibiarkan apa adanya.
type Result struct {
	Conds   []Cond
	Unknown map[string]bool
}

var criticalCategories = map[string]bool{"core": true, "distribution": true, "firewall": true}

// Evaluate menghasilkan kondisi problem dari keadaan perangkat. Fungsi murni (mudah diuji).
func Evaluate(devs []Device, th Thresholds) Result {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	res := Result{Unknown: map[string]bool{}}
	unknown := func(dev int64, kind string, idx *int) { res.Unknown[key(dev, kind, idx)] = true }

	for _, d := range devs {
		var conds []Cond
		add := func(c Cond) { c.DeviceID = d.ID; conds = append(conds, c) }

		if d.Status == "down" {
			sev := "high"
			if criticalCategories[d.Category] {
				sev = "disaster"
			}
			detail := d.Reason
			if detail == "" {
				detail = "tidak menjawab"
			}
			add(Cond{Kind: "down", Severity: sev, Title: d.Name + " down", Detail: detail})
		} else if d.Status == "up" {
			if d.PingEnabled {
				if !d.PingKnown || d.FailStreak > 0 {
					// data belum cukup, atau ping terakhir gagal total: biarkan penilaian down yang memutuskan
					unknown(d.ID, "loss", nil)
					unknown(d.ID, "latency", nil)
				} else {
					if d.LossAvg > th.LossPct {
						sev := "average"
						if d.LossAvg >= 50 {
							sev = "high"
						}
						v := d.LossAvg
						add(Cond{Kind: "loss", Severity: sev, Value: &v, Title: fmt.Sprintf("%s packet loss %.0f%%", d.Name, d.LossAvg),
							Detail: fmt.Sprintf("rata-rata 5 menit > %.0f%%", th.LossPct)})
					}
					if !math.IsNaN(d.RTTAvg) && d.RTTAvg > th.LatencyMS {
						v := d.RTTAvg
						add(Cond{Kind: "latency", Severity: "warning", Value: &v, Title: fmt.Sprintf("%s latensi tinggi %.0f ms", d.Name, d.RTTAvg),
							Detail: fmt.Sprintf("rata-rata 5 menit > %.0f ms", th.LatencyMS)})
					}
				}
			}
			if d.SNMPEnabled {
				if !d.SNMPKnown || (d.SNMPFails > 0 && d.SNMPFails < th.SNMPFailCycles) {
					// belum diamati, atau gagal tetapi belum mencapai ambang: problem baru ditutup setelah SNMP benar-benar berhasil lagi
					unknown(d.ID, "snmp", nil)
				} else if d.SNMPFails >= th.SNMPFailCycles {
					add(Cond{Kind: "snmp", Severity: "warning", Title: d.Name + ": SNMP tidak terbaca",
						Detail: fmt.Sprintf("gagal %d putaran berturut-turut; perangkat masih menjawab ping", d.SNMPFails)})
				}
			}
			for _, p := range d.Ports {
				idx := p.Index
				if p.AdminUp && !p.OperUp {
					add(Cond{Kind: "port_down", IfIndex: &idx, Severity: "high", Title: fmt.Sprintf("%s: port %s putus", d.Name, p.Name),
						Detail: "port dipantau dalam keadaan down"})
				}
				if !p.UtilKnown {
					unknown(d.ID, "port_util", &idx)
				} else if p.Util >= th.UtilPct {
					v := p.Util
					add(Cond{Kind: "port_util", IfIndex: &idx, Severity: "average", Value: &v,
						Title: fmt.Sprintf("%s: port %s penuh %.0f%%", d.Name, p.Name, p.Util), Detail: fmt.Sprintf("pemakaian ≥ %.0f%%", th.UtilPct)})
				}
			}
		} else {
			// status "unknown": belum dinilai — jangan tutup problem yang mungkin masih terbuka
			for _, k := range []string{"down", "loss", "latency", "snmp"} {
				unknown(d.ID, k, nil)
			}
			for _, p := range d.Ports {
				idx := p.Index
				unknown(d.ID, "port_down", &idx)
				unknown(d.ID, "port_util", &idx)
			}
		}

		for _, s := range d.Sensors {
			if !s.Fresh || d.Status != "up" {
				res.Unknown[SensorKey(d.ID, s.Key)] = true
				continue
			}
			if s.Status != "warn" && s.Status != "crit" {
				continue
			}
			sev := "warning"
			if s.Status == "crit" {
				sev = "high"
			}
			val := s.Text
			if val == "" && s.Value != nil {
				val = strings.TrimSpace(fmt.Sprintf("%g %s", *s.Value, s.Unit))
			}
			add(Cond{Kind: "sensor", Sensor: s.Key, Severity: sev, Value: s.Value, Title: fmt.Sprintf("%s: %s %s", d.Name, s.Label, val),
				Detail: "di luar ambang normal"})
		}

		// Konflik IP: tepat dua MAC bergantian berulang kali untuk IP yang sama di router yang sama.
		// Satu kali berganti (DHCP, ganti perangkat) BUKAN konflik.
		for _, c := range d.Conflicts {
			k := "ip:" + c.IP
			if d.Status != "up" {
				res.Unknown[SensorKey(d.ID, k)] = true
				continue
			}
			if len(c.MACs) == 2 && c.Changes >= th.ConflictChanges {
				n := float64(c.Changes)
				add(Cond{Kind: "ip_conflict", Sensor: k, Severity: "average", Value: &n,
					Title:  fmt.Sprintf("Konflik IP %s: %s ↔ %s", c.IP, c.MACs[0], c.MACs[1]),
					Detail: fmt.Sprintf("MAC berganti %d kali dalam %d jam di tabel ARP %s", c.Changes, th.ConflictHours, d.Name)})
			}
		}

		for _, c := range d.Cameras {
			k := fmt.Sprintf("cam:%d", c.ID)
			if !c.Fresh || d.Status != "up" {
				res.Unknown[SensorKey(d.ID, k)] = true // perangkat down sudah jadi problem sendiri
				continue
			}
			if c.Health == "" || c.Health == "ok" {
				continue
			}
			sev := "warning"
			if c.Health == "nosignal" {
				sev = "average"
			}
			add(Cond{Kind: "camera", Sensor: k, Severity: sev, Title: fmt.Sprintf("Kamera %s: %s", c.Name, c.Detail), Detail: "hasil cek gambar otomatis"})
		}

		sup, note := "", ""
		if d.InMaintenance != "" {
			sup, note = "maintenance", "maintenance: "+d.InMaintenance
		} else if anc := downAncestor(d, byID); anc != nil {
			sup, note = "parent", "terdampak: induk "+anc.Name+" down"
		}
		for i := range conds {
			conds[i].Suppressed, conds[i].SupNote = sup, note
		}
		res.Conds = append(res.Conds, conds...)
	}
	return res
}

// downAncestor mencari induk (naik sampai 16 tingkat) yang sedang down.
func downAncestor(d Device, byID map[int64]*Device) *Device {
	seen := map[int64]bool{d.ID: true}
	cur := d.ParentID
	for i := 0; i < 16 && cur != 0 && !seen[cur]; i++ {
		seen[cur] = true
		p, ok := byID[cur]
		if !ok {
			return nil
		}
		if p.Status == "down" {
			return p
		}
		cur = p.ParentID
	}
	return nil
}

// Summary membuat ringkasan singkat untuk log.
func Summary(r Result) string {
	n := map[string]int{}
	for _, c := range r.Conds {
		n[c.Kind]++
	}
	var parts []string
	for _, k := range []string{"down", "loss", "latency", "snmp", "port_down", "port_util"} {
		if n[k] > 0 {
			parts = append(parts, fmt.Sprintf("%s=%d", k, n[k]))
		}
	}
	if len(parts) == 0 {
		return "tidak ada"
	}
	return strings.Join(parts, " ")
}
