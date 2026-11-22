package problems

import (
	"context"
	"fmt"
	"math"
	"strings"
	"time"
)

// Trigger adalah aturan problem buatan engineer (tabel triggers).
type Trigger struct {
	ID          int64
	Name        string
	Severity    string
	Metric      string // latency | loss | sensor | port_in | port_out | port_util
	SensorClass string
	Match       string // saring nama sensor / port (huruf kecil), kosong = semua
	Op          string // > >= < <=
	Threshold   float64
	Duration    time.Duration
	Scope       string // all | site | category | devices
	SiteID      int64
	Category    string
	Devices     map[int64]bool
	Days        int  // bit 0 = Senin ... bit 6 = Minggu
	StartMin    *int // jam aktif, nil = sepanjang hari
	EndMin      *int
}

// PortVal adalah nilai port terkini dari tabel interfaces.
type PortVal struct {
	Index         int
	Name, Alias   string
	InBps, OutBps *float64
	Util          *float64
	Fresh         bool // diperbarui ≤ 20 menit
}

// TriggerState menyimpan sejak kapan kondisi trigger terpenuhi (untuk syarat "bertahan N menit").
// Hilang saat collector restart: hitungan durasi dimulai lagi, tidak ada problem palsu.
type TriggerState map[string]time.Time

func (t Trigger) applies(d Device) bool {
	switch t.Scope {
	case "site":
		return d.SiteID == t.SiteID
	case "category":
		return d.Category == t.Category
	case "devices":
		return t.Devices[d.ID]
	}
	return true
}

// Active: apakah trigger sedang dalam jadwal aktif pada waktu lokal now.
func (t Trigger) Active(now time.Time) bool {
	wd := (int(now.Weekday()) + 6) % 7 // Senin = 0
	if t.Days&(1<<wd) == 0 {
		return false
	}
	if t.StartMin == nil || t.EndMin == nil {
		return true
	}
	m := now.Hour()*60 + now.Minute()
	if *t.StartMin <= *t.EndMin {
		return m >= *t.StartMin && m < *t.EndMin
	}
	return m >= *t.StartMin || m < *t.EndMin // melewati tengah malam, mis. 22:00 sampai 06:00
}

func (t Trigger) hit(v float64) bool {
	switch t.Op {
	case ">":
		return v > t.Threshold
	case ">=":
		return v >= t.Threshold
	case "<":
		return v < t.Threshold
	case "<=":
		return v <= t.Threshold
	}
	return false
}

var metricUnit = map[string]string{"latency": " ms", "loss": "%", "port_in": " Mbps", "port_out": " Mbps", "port_util": "%"}
var metricName = map[string]string{"latency": "latensi", "loss": "packet loss", "port_in": "trafik masuk", "port_out": "trafik keluar", "port_util": "pemakaian"}

func num(v float64) string {
	format := "%.2f"
	if math.Abs(v) < 1 && v != 0 {
		format = "%.3f" // trafik kecil (mis. 0,002 Mbps) tidak dibulatkan jadi 0
	}
	s := fmt.Sprintf(format, v)
	s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
	return strings.Replace(s, ".", ",", 1)
}

// EvaluateTriggers menilai trigger untuk semua perangkat. Fungsi murni kecuali state (diperbarui di tempat).
func EvaluateTriggers(trs []Trigger, devs []Device, ports map[int64][]PortVal, now time.Time, st TriggerState) Result {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	res := Result{Unknown: map[string]bool{}}
	seen := map[string]bool{}

	for _, t := range trs {
		active := t.Active(now)
		for _, d := range devs {
			if !t.applies(d) {
				continue
			}
			type sample struct {
				key, what, unit string
				val             float64
				idx             *int
				sensor          bool
			}
			var samples []sample
			unknownAll := func(prefix string) { res.Unknown[SensorKey(d.ID, prefix)] = true }
			base := fmt.Sprintf("trg:%d", t.ID)

			if d.Status != "up" {
				// perangkat down/belum dinilai: problem trigger yang terbuka dibiarkan (down sudah jadi problem sendiri)
				unknownAll(base)
				for _, p := range ports[d.ID] {
					res.Unknown[SensorKey(d.ID, fmt.Sprintf("%s:if%d", base, p.Index))] = true
				}
				for _, s := range d.Sensors {
					res.Unknown[SensorKey(d.ID, trimKey(base+":"+s.Key))] = true
				}
				continue
			}
			switch t.Metric {
			case "latency", "loss":
				if !d.PingEnabled {
					continue
				}
				if !d.PingKnown || d.FailStreak > 0 {
					unknownAll(base)
					continue
				}
				v := d.LossAvg
				if t.Metric == "latency" {
					if math.IsNaN(d.RTTAvg) {
						unknownAll(base)
						continue
					}
					v = d.RTTAvg
				}
				samples = append(samples, sample{key: base, val: v, what: metricName[t.Metric]})
			case "sensor":
				for _, s := range d.Sensors {
					if s.Class != t.SensorClass || (t.Match != "" && !strings.Contains(strings.ToLower(s.Label+" "+s.Key), t.Match)) {
						continue
					}
					k := trimKey(base + ":" + s.Key)
					if !s.Fresh || s.Value == nil {
						res.Unknown[SensorKey(d.ID, k)] = true
						continue
					}
					u := ""
					if strings.TrimSpace(s.Unit) != "" {
						u = " " + strings.TrimSpace(s.Unit)
					}
					samples = append(samples, sample{key: k, val: *s.Value, what: s.Label, unit: u, sensor: true})
				}
			case "port_in", "port_out", "port_util":
				for _, p := range ports[d.ID] {
					if t.Match != "" && !strings.Contains(strings.ToLower(p.Name+" "+p.Alias), t.Match) {
						continue
					}
					idx := p.Index
					k := fmt.Sprintf("%s:if%d", base, p.Index)
					var v *float64
					switch t.Metric {
					case "port_in":
						v = mbps(p.InBps)
					case "port_out":
						v = mbps(p.OutBps)
					default:
						v = p.Util
					}
					if !p.Fresh || v == nil {
						res.Unknown[SensorKey(d.ID, k)] = true
						continue
					}
					samples = append(samples, sample{key: k, val: *v, what: metricName[t.Metric] + " port " + p.Name, idx: &idx})
				}
			}

			for _, s := range samples {
				sk := SensorKey(d.ID, s.key)
				seen[sk] = true
				if !active || !t.hit(s.val) {
					delete(st, sk)
					continue
				}
				since, ok := st[sk]
				if !ok {
					st[sk] = now
					since = now
				}
				if now.Sub(since) < t.Duration {
					res.Unknown[sk] = true // belum bertahan cukup lama: problem belum dibuat, yang sudah terbuka (mis. sebelum restart) dibiarkan
					continue
				}
				v := s.val
				unit := metricUnit[t.Metric]
				if s.sensor {
					unit = s.unit
				}
				cond := Cond{DeviceID: d.ID, Kind: "custom", Sensor: s.key, IfIndex: s.idx, Severity: t.Severity, Value: &v,
					Title:  trimKey2(fmt.Sprintf("%s: %s (%s%s)", d.Name, t.Name, num(v), unit), 250),
					Detail: trimKey2(fmt.Sprintf("trigger: %s %s %s%s%s", s.what, t.Op, num(t.Threshold), unit, durText(t.Duration)), 480)}
				if d.InMaintenance != "" {
					cond.Suppressed, cond.SupNote = "maintenance", "maintenance: "+d.InMaintenance
				} else if anc := downAncestor(d, byID); anc != nil {
					cond.Suppressed, cond.SupNote = "parent", "terdampak: induk "+anc.Name+" down"
				}
				res.Conds = append(res.Conds, cond)
			}
		}
	}
	// state milik trigger/sampel yang sudah tidak ada dibuang
	for k := range st {
		if !seen[k] {
			delete(st, k)
		}
	}
	return res
}

func durText(d time.Duration) string {
	if d <= 0 {
		return ", langsung"
	}
	return fmt.Sprintf(" selama ≥ %d menit", int(d.Minutes()))
}

func mbps(v *float64) *float64 {
	if v == nil {
		return nil
	}
	x := *v / 1e6
	return &x
}

func trimKey(s string) string { return trimKey2(s, 96) }

func trimKey2(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return strings.ToValidUTF8(s[:n], "")
}

// loadTriggers membaca trigger aktif dan, bila ada trigger port, nilai port terkini perangkat yang relevan.
func (e *Engine) loadTriggers(ctx context.Context) ([]Trigger, map[int64][]PortVal, error) {
	rows, err := e.DB.Query(ctx, `SELECT t.id, t.name, t.severity, t.metric, COALESCE(t.sensor_class, ''), lower(COALESCE(t.match_text, '')), t.op, t.threshold,
			t.duration_min, t.scope, COALESCE(t.site_id, 0), COALESCE(t.category, ''), t.days, t.start_min, t.end_min,
			COALESCE((SELECT array_agg(device_id) FROM trigger_devices td WHERE td.trigger_id = t.id), '{}')
		FROM triggers t WHERE t.enabled ORDER BY t.id`)
	if err != nil {
		return nil, nil, err
	}
	var trs []Trigger
	portTrig := false
	for rows.Next() {
		var t Trigger
		var dur int
		var devIDs []int64
		if err := rows.Scan(&t.ID, &t.Name, &t.Severity, &t.Metric, &t.SensorClass, &t.Match, &t.Op, &t.Threshold, &dur, &t.Scope, &t.SiteID, &t.Category,
			&t.Days, &t.StartMin, &t.EndMin, &devIDs); err != nil {
			rows.Close()
			return nil, nil, err
		}
		t.Duration = time.Duration(dur) * time.Minute
		t.Devices = map[int64]bool{}
		for _, id := range devIDs {
			t.Devices[id] = true
		}
		if strings.HasPrefix(t.Metric, "port_") {
			portTrig = true
		}
		trs = append(trs, t)
	}
	rows.Close()
	if err := rows.Err(); err != nil || !portTrig {
		return trs, nil, err
	}
	prow, err := e.DB.Query(ctx, `SELECT device_id, if_index, COALESCE(NULLIF(name, ''), 'if' || if_index), COALESCE(alias, ''), in_bps, out_bps, util_pct,
			updated_at > now() - interval '20 minutes'
		FROM interfaces WHERE oper_up AND (in_bps IS NOT NULL OR util_pct IS NOT NULL)`)
	if err != nil {
		return trs, nil, err
	}
	defer prow.Close()
	ports := map[int64][]PortVal{}
	for prow.Next() {
		var dev int64
		var p PortVal
		if err := prow.Scan(&dev, &p.Index, &p.Name, &p.Alias, &p.InBps, &p.OutBps, &p.Util, &p.Fresh); err != nil {
			return trs, nil, err
		}
		ports[dev] = append(ports[dev], p)
	}
	return trs, ports, prow.Err()
}
