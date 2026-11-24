package sitecollector

import (
	"context"
	"sort"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/checks"
	"github.com/aidilandriandas/antarejanms/collector/internal/modbus"
	"github.com/aidilandriandas/antarejanms/collector/internal/ping"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// IngestResult ringkasan penerapan satu kiriman.
type IngestResult struct {
	Pings    int   `json:"pings"`
	SNMP     int   `json:"snmp"`
	Checks   int   `json:"checks"`
	Modbus   int   `json:"modbus"`
	Skipped  int   `json:"skipped"` // sampel ganda, terlalu lama, atau milik perangkat lain
	SkewSecs int64 `json:"skew_s"`
}

// maxSkew selisih jam site yang masih dibiarkan; lebih dari ini, waktu sampel digeser mengikuti jam pusat.
const maxSkew = 30 * time.Second

// Ingest menerapkan kiriman collector site ke database pusat lewat penilaian yang sama dengan collector pusat.
// Hanya perangkat aktif milik collector ini yang diterima. Sampel diterapkan berurutan waktu, dan sampel yang
// sudah pernah diterapkan (kiriman ulang setelah jawaban hilang) dilewati supaya hitungan gagal tidak ganda.
func Ingest(ctx context.Context, p *poller.Poller, collectorID int64, b Batch) (IngestResult, error) {
	return IngestAll(ctx, Appliers{Poller: p}, collectorID, b)
}

// Appliers penerap hasil di pusat. Checks/Modbus nil: sampel jenis itu dilewati.
type Appliers struct {
	Poller *poller.Poller
	Checks *checks.Runner
	Modbus *modbus.Runner
}

// IngestAll seperti Ingest, ditambah hasil cek layanan dan Modbus.
func IngestAll(ctx context.Context, ap Appliers, collectorID int64, b Batch) (IngestResult, error) {
	p := ap.Poller
	var res IngestResult
	now := time.Now()
	var shift time.Duration
	if !b.SentAt.IsZero() {
		if skew := now.Sub(b.SentAt); skew > maxSkew || skew < -maxSkew {
			shift = skew
		}
		res.SkewSecs = int64(now.Sub(b.SentAt).Seconds())
	}
	fix := func(t time.Time) time.Time {
		t = t.Add(shift).Truncate(time.Second)
		if t.After(now) {
			t = now.Truncate(time.Second)
		}
		return t
	}

	var lastPing *time.Time
	_ = p.Store.DB.QueryRow(ctx, `SELECT last_ping_at FROM site_collectors WHERE id = $1`, collectorID).Scan(&lastPing)

	// Ping: dikelompokkan per putaran, diterapkan dari yang terlama dengan status perangkat terkini.
	groups := map[time.Time]map[int64]ping.Result{}
	for _, s := range b.Pings {
		at := fix(s.At)
		if lastPing != nil && !at.After(*lastPing) {
			res.Skipped++
			continue
		}
		if groups[at] == nil {
			groups[at] = map[int64]ping.Result{}
		}
		groups[at][s.DeviceID] = s.Result
	}
	times := make([]time.Time, 0, len(groups))
	for t := range groups {
		times = append(times, t)
	}
	sort.Slice(times, func(i, j int) bool { return times[i].Before(times[j]) })
	for _, at := range times {
		devs, err := p.Store.CollectorDevices(ctx, collectorID)
		if err != nil {
			return res, err
		}
		results := groups[at]
		var targets []store.Device
		for _, d := range devs {
			if _, ok := results[d.ID]; ok && d.PingEnabled {
				targets = append(targets, d)
			}
		}
		res.Skipped += len(results) - len(targets)
		if _, err := p.ApplyPing(ctx, targets, results, at); err != nil {
			return res, err
		}
		res.Pings += len(targets)
		if _, err := p.Store.DB.Exec(ctx, `UPDATE site_collectors SET last_ping_at = $2 WHERE id = $1`, collectorID, at); err != nil {
			return res, err
		}
	}

	// SNMP: per perangkat, dilewati bila tidak lebih baru dari pembacaan terakhir yang tersimpan.
	snmps := append([]poller.SNMPSample(nil), b.SNMP...)
	sort.SliceStable(snmps, func(i, j int) bool { return snmps[i].At.Before(snmps[j].At) })
	for _, s := range snmps {
		s.At = fix(s.At)
		d, err := p.Store.Device(ctx, s.DeviceID)
		if err != nil || d.CollectorID == nil || *d.CollectorID != collectorID || !d.SNMPEnabled ||
			(d.LastSNMPAt != nil && !s.At.After(*d.LastSNMPAt)) {
			res.Skipped++
			continue
		}
		p.ApplySNMP(ctx, d, s)
		res.SNMP++
	}
	if res.SNMP > 0 {
		p.RecomputeMACs(ctx)
	}

	// Cek layanan dan Modbus: diterapkan berurutan waktu, kepemilikan & kiriman ganda diperiksa penerapnya.
	cks := append([]CheckSample(nil), b.Checks...)
	sort.SliceStable(cks, func(i, j int) bool { return cks[i].At.Before(cks[j].At) })
	for _, c := range cks {
		ok := false
		if ap.Checks != nil {
			var err error
			if ok, err = ap.Checks.ApplySample(ctx, collectorID, c.CheckID, c.Result, fix(c.At)); err != nil {
				return res, err
			}
		}
		if ok {
			res.Checks++
		} else {
			res.Skipped++
		}
	}
	mbs := append([]ModbusSample(nil), b.Modbus...)
	sort.SliceStable(mbs, func(i, j int) bool { return mbs[i].At.Before(mbs[j].At) })
	for _, m := range mbs {
		ok := false
		if ap.Modbus != nil {
			var err error
			if ok, err = ap.Modbus.ApplySample(ctx, collectorID, m.DeviceID, m.Readings, m.Error, fix(m.At)); err != nil {
				return res, err
			}
		}
		if ok {
			res.Modbus++
		} else {
			res.Skipped++
		}
	}

	var newest *time.Time
	if len(times) > 0 {
		t := times[len(times)-1]
		newest = &t
	}
	for _, s := range snmps {
		if newest == nil || s.At.After(*newest) {
			t := s.At
			newest = &t
		}
	}
	_, err := p.Store.DB.Exec(ctx, `UPDATE site_collectors SET newest_sample_at = GREATEST(newest_sample_at, $2), clock_skew_s = $3,
		queued = $4, version = NULLIF($5, ''), updated_at = now() WHERE id = $1`, collectorID, newest, res.SkewSecs, b.Queued, trimTo(b.Version, 32))
	if (res.Pings > 0 || res.Checks > 0 || res.Modbus > 0) && p.AfterPing != nil {
		p.AfterPing(ctx) // problem & notifikasi dinilai ulang dengan status baru
	}
	return res, err
}

func trimTo(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
