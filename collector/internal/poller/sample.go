package poller

import (
	"context"
	"math"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/metrics"
	"github.com/aidilandriandas/antarejanms/collector/internal/ping"
	"github.com/aidilandriandas/antarejanms/collector/internal/profiles"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// Pengukuran dipisah dari penilaian: collector site hanya mengukur lalu mengirim sampel ke pusat, dan pusat
// menilai status, mencatat kejadian, serta menyimpan dengan kode yang sama seperti hasil pengukurannya sendiri.

// PingSample hasil ping satu perangkat pada satu putaran.
type PingSample struct {
	DeviceID int64       `json:"device_id"`
	At       time.Time   `json:"at"`
	Result   ping.Result `json:"result"`
}

// Sensor bacaan sensor dalam bentuk yang bisa dikirim sebagai JSON (nilai kosong = nil, bukan NaN).
type Sensor struct {
	Key    string   `json:"key"`
	Class  string   `json:"class"`
	Group  string   `json:"group,omitempty"`
	Label  string   `json:"label"`
	Unit   string   `json:"unit,omitempty"`
	Value  *float64 `json:"value,omitempty"`
	Text   string   `json:"text,omitempty"`
	Status string   `json:"status,omitempty"`
}

// SNMPSample hasil mentah satu pembacaan SNMP perangkat.
type SNMPSample struct {
	DeviceID int64     `json:"device_id"`
	At       time.Time `json:"at"`
	Err      string    `json:"err,omitempty"` // SNMP tidak terbaca (pesan ramah)

	Sys         *snmp.System     `json:"sys,omitempty"`
	Ifs         []snmp.Interface `json:"ifs,omitempty"`
	IfErr       string           `json:"if_err,omitempty"`
	Neighbors   []snmp.Neighbor  `json:"neighbors,omitempty"`
	NeighborsOK bool             `json:"neighbors_ok,omitempty"`

	Profiles         string   `json:"profiles,omitempty"`
	ProfilesDetected bool     `json:"profiles_detected,omitempty"`
	ProfilesOK       bool     `json:"profiles_ok,omitempty"`
	Sensors          []Sensor `json:"sensors,omitempty"`

	Addrs   []snmp.IfAddr   `json:"addrs,omitempty"`
	AddrsOK bool            `json:"addrs_ok,omitempty"`
	ARP     []snmp.ARPEntry `json:"arp,omitempty"`
	FDB     []snmp.FDBEntry `json:"fdb,omitempty"` // tabel MAC switch

	Routing *snmp.Routing   `json:"routing,omitempty"` // sesi BGP & tetangga OSPF (router)
	Clock   *snmp.Clock     `json:"clock,omitempty"`   // selisih jam perangkat
	Inv     *snmp.Inventory `json:"inv,omitempty"`     // versi & model, dibaca tiap 24 jam
}

// Ambang selisih jam (detik, nilai mutlak). Log, sertifikat, dan korelasi kejadian kacau bila jam perangkat meleset.
const (
	clockWarnSec = 60
	clockCritSec = 300
	invEvery     = 24 * time.Hour
)

func (p *Poller) inventoryDue(id int64, now time.Time) bool {
	p.invMu.Lock()
	defer p.invMu.Unlock()
	if p.invAt == nil {
		p.invAt = map[int64]time.Time{}
	}
	if t, ok := p.invAt[id]; ok && now.Sub(t) < invEvery {
		return false
	}
	p.invAt[id] = now
	return true
}

// ClockSensor mengubah selisih jam menjadi sensor kelas clock_skew (masuk problem sensor seperti sensor lain).
func ClockSensor(c snmp.Clock) Sensor {
	v := math.Round(c.SkewSec)
	st, a := "ok", math.Abs(v)
	switch {
	case a >= clockCritSec:
		st = "crit"
	case a >= clockWarnSec:
		st = "warn"
	}
	label := "Selisih jam"
	if !c.HasTZ {
		label = "Selisih jam (tanpa zona waktu)"
	}
	return Sensor{Key: "clock", Class: "clock_skew", Group: "sistem", Label: label, Unit: "detik", Value: &v, Status: st}
}

// ProfileFunc menentukan profil sensor perangkat: ids dipisah koma, detected = baru dideteksi ulang,
// ok = false bila profil tidak bisa ditentukan (sensor dilewati putaran ini).
type ProfileFunc func(objectID string, sess *snmp.Session) (ids string, detected, ok bool)

// MeasureSNMP membaca perangkat lewat SNMP tanpa menyentuh database.
func (p *Poller) MeasureSNMP(d store.Device, cr snmp.Credentials, credErr error, prof ProfileFunc) SNMPSample {
	s := SNMPSample{DeviceID: d.ID, At: time.Now().Truncate(time.Second)}
	if credErr != nil {
		s.Err = snmp.Friendly(credErr)
		return s
	}
	sys, err := p.SNMP.System(d.Host, cr)
	if err != nil {
		s.Err = snmp.Friendly(err)
		return s
	}
	s.Sys = &sys
	if ifs, err := p.SNMP.Interfaces(d.Host, cr); err != nil {
		s.IfErr = snmp.Friendly(err)
	} else {
		s.Ifs = ifs
		if nbs, err := p.SNMP.Neighbors(d.Host, cr, ifs); err == nil {
			s.Neighbors, s.NeighborsOK = nbs, true
		}
	}
	if sess, err := p.SNMP.Session(d.Host, cr); err == nil {
		ids, detected, ok := prof(sys.ObjectID, sess)
		s.Profiles, s.ProfilesDetected, s.ProfilesOK = ids, detected, ok
		if ok && ids != "" {
			for _, r := range profiles.Collect(sess, strings.Split(ids, ","), d.Limits().Classes) {
				sn := Sensor{Key: r.Key, Class: r.Class, Group: r.Group, Label: r.Label, Unit: r.Unit, Text: r.Text, Status: r.Status}
				if !math.IsNaN(r.Value) && !math.IsInf(r.Value, 0) {
					v := r.Value
					sn.Value = &v
				}
				s.Sensors = append(s.Sensors, sn)
			}
		}
		sess.Close()
	}
	if c, err := p.SNMP.Clock(d.Host, cr); err == nil {
		s.Clock = &c
		if s.ProfilesOK && s.Profiles != "" {
			s.Sensors = append(s.Sensors, ClockSensor(c))
		}
	}
	if p.inventoryDue(d.ID, s.At) {
		if inv, err := p.SNMP.Inventory(d.Host, cr, sys.Descr, sys.ObjectID); err == nil {
			s.Inv = &inv
		}
	}
	if addrs, err := p.SNMP.Addresses(d.Host, cr); err == nil {
		s.Addrs, s.AddrsOK = addrs, true
	}
	if arpCategories[d.Category] || strings.HasPrefix(sys.ObjectID, ".1.3.6.1.4.1.14988") {
		if arp, err := p.SNMP.ARP(d.Host, cr); err == nil {
			s.ARP = arp
		}
	}
	if arpCategories[d.Category] || strings.HasPrefix(sys.ObjectID, ".1.3.6.1.4.1.14988") {
		if rt, err := p.SNMP.Routing(d.Host, cr); err == nil {
			s.Routing = &rt
		}
	}
	if fdbCategories[d.Category] {
		if fdb, err := p.SNMP.FDB(d.Host, cr); err == nil {
			s.FDB = fdb
		}
	}
	return s
}

// storeProfiles: profil dari database (dideteksi ulang bila sysObjectID berubah atau tiap 24 jam).
func (p *Poller) storeProfiles(ctx context.Context, d store.Device) ProfileFunc {
	return func(objectID string, sess *snmp.Session) (string, bool, bool) {
		ids, refresh, err := p.Store.Profiles(ctx, d.ID, objectID, d.SysObjectID)
		if err != nil {
			p.Log.Warn("baca profil gagal", "perangkat", d.ID, "galat", err)
			return "", false, false
		}
		if refresh {
			return strings.Join(profiles.Detect(sess, objectID), ","), true, true
		}
		return ids, false, true
	}
}

// ProfileCache profil sensor di collector site (tanpa database): dideteksi saat pertama, saat sysObjectID
// berubah, dan tiap 24 jam.
type ProfileCache struct {
	mu sync.Mutex
	m  map[int64]profEntry
}

type profEntry struct {
	objectID, ids string
	at            time.Time
}

func (c *ProfileCache) For(id int64) ProfileFunc {
	return func(objectID string, sess *snmp.Session) (string, bool, bool) {
		c.mu.Lock()
		e, ok := c.m[id]
		c.mu.Unlock()
		if ok && e.objectID == objectID && time.Since(e.at) < 24*time.Hour {
			return e.ids, false, true
		}
		ids := strings.Join(profiles.Detect(sess, objectID), ",")
		c.mu.Lock()
		if c.m == nil {
			c.m = map[int64]profEntry{}
		}
		c.m[id] = profEntry{objectID: objectID, ids: ids, at: time.Now()}
		c.mu.Unlock()
		return ids, true, true
	}
}

// ApplySNMP menilai & menyimpan satu sampel SNMP (milik sendiri atau dari collector site).
func (p *Poller) ApplySNMP(ctx context.Context, d store.Device, s SNMPSample) bool {
	now := s.At
	u := store.SNMPUpdate{ID: d.ID, At: now}
	if s.Err != "" || s.Sys == nil {
		msg := s.Err
		if msg == "" {
			msg = "tidak ada jawaban"
		}
		u.Error = &msg
		if d.SNMPOK == nil || *d.SNMPOK {
			p.event(ctx, d.ID, "snmp_fail", d.Name+": SNMP tidak terbaca: "+msg, nil, now)
		}
		if !d.PingEnabled { // status hanya dari SNMP
			streak := d.FailStreak + 1
			u.FailStreak = &streak
			if streak >= p.Cfg.DownAfter {
				st := "down"
				u.Status, u.Reason = &st, &msg
				u.Changed = d.Status != "down"
				if u.Changed {
					p.transition(ctx, d, "down", msg, now)
				}
			}
		}
		if err := p.Store.SaveSNMP(ctx, u); err != nil {
			p.Log.Error("simpan SNMP gagal", "perangkat", d.ID, "galat", err)
		}
		p.Stats.SNMPResult(d.ID, false)
		return false
	}

	sys := *s.Sys
	p.Stats.SNMPResult(d.ID, true)
	u.OK = true
	up := sys.UptimeSeconds()
	u.SysName, u.SysDescr, u.SysObjectID, u.SysLoc, u.Uptime = &sys.Name, &sys.Descr, &sys.ObjectID, &sys.Location, &up
	if d.SNMPOK != nil && !*d.SNMPOK {
		p.event(ctx, d.ID, "snmp_ok", d.Name+": SNMP kembali terbaca", nil, now)
	}
	if d.UptimeSeconds != nil && d.LastSNMPAt != nil &&
		snmp.Rebooted(uint32(*d.UptimeSeconds*100), sys.UptimeTicks, now.Sub(*d.LastSNMPAt)) {
		p.event(ctx, d.ID, "reboot", d.Name+" restart (uptime sebelumnya "+Human(time.Duration(*d.UptimeSeconds)*time.Second)+
			", sekarang "+Human(time.Duration(up)*time.Second)+")", nil, now)
	}
	if !d.PingEnabled {
		zero, st := 0, "up"
		u.FailStreak, u.Status = &zero, &st
		u.Changed = d.Status != "up"
		if u.Changed {
			p.transition(ctx, d, "up", "", now)
		}
	}
	if err := p.Store.SaveSNMP(ctx, u); err != nil {
		p.Log.Error("simpan SNMP gagal", "perangkat", d.ID, "galat", err)
	}

	var batch metrics.Batch
	dl := map[string]string{"device_id": strconv.FormatInt(d.ID, 10)}
	batch.Add("antareja_snmp_uptime_seconds", dl, float64(up), now)

	if s.IfErr != "" {
		p.Log.Warn("baca port gagal", "perangkat", d.ID, "galat", s.IfErr)
	} else {
		rows := make([]store.InterfaceRow, 0, len(s.Ifs))
		for _, it := range s.Ifs {
			inBps, outBps, util := p.Stats.Rate(d.ID, it.Index, it.InOctets, it.OutOctets, it.SpeedBps, now)
			errRate, discRate := p.Stats.ErrRate(d.ID, it.Index, it.InErrors, it.OutErrors, it.InDiscards, it.OutDiscards, now)
			rows = append(rows, store.InterfaceRow{Index: it.Index, Name: it.Name, Alias: it.Alias, Descr: it.Descr, MAC: it.MAC,
				Type: it.Type, SpeedBps: int64(it.SpeedBps), AdminUp: it.AdminUp, OperUp: it.OperUp, InBps: inBps, OutBps: outBps, Util: util,
				ErrRate: errRate, DiscardRate: discRate})
			l := map[string]string{"device_id": dl["device_id"], "if_index": strconv.Itoa(it.Index), "if_name": it.Name}
			batch.Add("antareja_if_oper_up", l, b2f(it.OperUp), now)
			batch.Add("antareja_if_speed_bps", l, float64(it.SpeedBps), now)
			addCounter(&batch, "antareja_if_in_octets_total", l, it.InOctets, now)
			addCounter(&batch, "antareja_if_out_octets_total", l, it.OutOctets, now)
			addCounter(&batch, "antareja_if_in_errors_total", l, it.InErrors, now)
			addCounter(&batch, "antareja_if_out_errors_total", l, it.OutErrors, now)
			addCounter(&batch, "antareja_if_in_ucast_pkts_total", l, it.InUcast, now)
			addCounter(&batch, "antareja_if_out_ucast_pkts_total", l, it.OutUcast, now)
			addCounter(&batch, "antareja_if_in_mcast_pkts_total", l, it.InMcast, now)
			addCounter(&batch, "antareja_if_out_mcast_pkts_total", l, it.OutMcast, now)
			addCounter(&batch, "antareja_if_in_bcast_pkts_total", l, it.InBcast, now)
			addCounter(&batch, "antareja_if_out_bcast_pkts_total", l, it.OutBcast, now)
			addCounter(&batch, "antareja_if_in_discards_total", l, it.InDiscards, now)
			addCounter(&batch, "antareja_if_out_discards_total", l, it.OutDiscards, now)
		}
		if err := p.Store.SaveInterfaces(ctx, d.ID, rows); err != nil {
			p.Log.Error("simpan port gagal", "perangkat", d.ID, "galat", err)
		}
		// Tetangga LLDP/CDP untuk peta topologi
		if s.NeighborsOK {
			nrows := make([]store.NeighborRow, 0, len(s.Neighbors))
			for _, n := range s.Neighbors {
				nrows = append(nrows, store.NeighborRow{LocalIfIndex: n.LocalIfIndex, LocalPort: n.LocalPortName, Protocol: n.Protocol,
					Name: n.RemoteName, Port: n.RemotePort, Chassis: n.RemoteChassis, IP: n.RemoteIP, Platform: n.RemotePlatform})
			}
			if err := p.Store.SaveNeighbors(ctx, d.ID, nrows); err != nil {
				p.Log.Error("simpan tetangga gagal", "perangkat", d.ID, "galat", err)
			}
		}
	}

	// Sensor
	if s.ProfilesDetected {
		if err := p.Store.SetProfiles(ctx, d.ID, s.Profiles); err != nil {
			p.Log.Warn("simpan profil gagal", "perangkat", d.ID, "galat", err)
		}
		p.Log.Info("profil perangkat", "perangkat", d.ID, "profil", s.Profiles)
	}
	if s.ProfilesOK && s.Profiles != "" {
		rows := make([]store.SensorRow, 0, len(s.Sensors))
		dev := dl["device_id"]
		for _, r := range s.Sensors {
			row := store.SensorRow{Key: r.Key, Class: r.Class, Group: r.Group, Label: r.Label, Unit: r.Unit, Text: r.Text, Status: r.Status, Value: r.Value}
			if r.Value != nil {
				batch.Add("antareja_sensor", map[string]string{"device_id": dev, "key": r.Key, "class": r.Class}, *r.Value, now)
			}
			rows = append(rows, row)
		}
		if err := p.Store.SaveSensors(ctx, d.ID, rows); err != nil {
			p.Log.Error("simpan sensor gagal", "perangkat", d.ID, "galat", err)
		}
	}

	if s.Routing != nil {
		evs, err := p.Store.SaveRouting(ctx, d.ID, *s.Routing)
		if err != nil {
			p.Log.Warn("simpan BGP/OSPF gagal", "perangkat", d.ID, "galat", err)
		}
		for _, m := range evs {
			_ = p.Store.Event(ctx, d.ID, "routing", m, nil, now)
		}
	}
	if s.Inv != nil {
		if old, changed, err := p.Store.SaveInventory(ctx, d.ID, s.Inv.Version, s.Inv.Model, s.Inv.Serial); err != nil {
			p.Log.Warn("simpan versi perangkat gagal", "perangkat", d.ID, "galat", err)
		} else if changed {
			if err := p.Store.Event(ctx, d.ID, "firmware", "Versi berubah dari "+old+" ke "+s.Inv.Version, nil, now); err != nil {
				p.Log.Warn("catat kejadian versi gagal", "perangkat", d.ID, "galat", err)
			}
		}
	}

	// IPAM
	if s.AddrsOK {
		rows := make([]store.IPRow, 0, len(s.Addrs))
		for _, a := range s.Addrs {
			rows = append(rows, store.IPRow{IP: a.IP, IfIndex: a.IfIndex, Prefix: a.Prefix})
		}
		if err := p.Store.SaveIPs(ctx, d.ID, rows); err != nil {
			p.Log.Warn("simpan IP perangkat gagal", "perangkat", d.ID, "galat", err)
		}
	}
	if len(s.ARP) > 0 {
		rows := make([]store.ARPRow, 0, len(s.ARP))
		for _, a := range s.ARP {
			rows = append(rows, store.ARPRow{IfIndex: a.IfIndex, IP: a.IP, MAC: a.MAC})
		}
		if n, err := p.Store.SaveARP(ctx, d.ID, rows); err != nil {
			p.Log.Warn("simpan ARP gagal", "perangkat", d.ID, "galat", err)
		} else if n > 0 {
			p.Log.Info("pergantian MAC di tabel ARP", "router", d.ID, "jumlah", n)
		}
	}

	if len(s.FDB) > 0 {
		rows := make([]store.FDBRow, 0, len(s.FDB))
		for _, f := range s.FDB {
			rows = append(rows, store.FDBRow{IfIndex: f.IfIndex, VLAN: f.VLAN, MAC: f.MAC})
		}
		if err := p.Store.SaveFDB(ctx, d.ID, rows); err != nil {
			p.Log.Warn("simpan tabel MAC gagal", "perangkat", d.ID, "galat", err)
		}
	}

	if err := p.Writer.Write(ctx, &batch); err != nil {
		p.Log.Warn("metrik SNMP tidak terkirim", "perangkat", d.ID, "galat", err)
	}
	return true
}

// ApplyPing menilai & menyimpan hasil ping satu putaran (perangkat tanpa hasil dilewati).
func (p *Poller) ApplyPing(ctx context.Context, targets []store.Device, results map[int64]ping.Result, now time.Time) (int, error) {
	var batch metrics.Batch
	ups := make([]store.PingUpdate, 0, len(targets))
	down := 0
	for _, d := range targets {
		r, ok := results[d.ID]
		if !ok || !d.PingEnabled {
			continue
		}
		u := p.judgePing(ctx, d, r, now)
		p.Stats.AddPing(d.ID, r.OK(), r.AvgMS, r.LossPct)
		ups = append(ups, u)
		if u.Status == "down" {
			down++
		}
		l := map[string]string{"device_id": strconv.FormatInt(d.ID, 10)}
		batch.Add("antareja_ping_up", l, b2f(r.OK()), now)
		batch.Add("antareja_ping_loss_pct", l, r.LossPct, now)
		if r.OK() {
			batch.Add("antareja_ping_rtt_ms", l, r.AvgMS, now)
			batch.Add("antareja_ping_rtt_min_ms", l, r.MinMS, now)
			batch.Add("antareja_ping_rtt_max_ms", l, r.MaxMS, now)
			batch.Add("antareja_ping_jitter_ms", l, r.Jitter, now)
		}
	}
	if err := p.Store.SavePing(ctx, ups); err != nil {
		return down, err
	}
	if err := p.Writer.Write(ctx, &batch); err != nil {
		p.Log.Warn("metrik ping tidak terkirim", "galat", err)
	}
	return down, nil
}

// LocalPingFailure: semua ping gagal karena galat lokal (mis. izin raw socket), bukan karena perangkat diam.
// Hasil seperti ini tidak boleh dipakai menilai status.
func LocalPingFailure(results map[string]ping.Result) (bool, string) {
	if len(results) == 0 {
		return false, ""
	}
	msg := ""
	for _, r := range results {
		if r.OK() || r.Err == "" {
			return false, ""
		}
		msg = r.Err
	}
	return true, msg
}
