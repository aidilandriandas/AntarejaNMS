package modbus

import (
	"context"
	"errors"
	"log/slog"
	"strconv"
	"sync"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/metrics"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// Point satu titik baca.
type Point struct {
	ID       int64    `json:"id"`
	Label    string   `json:"label"`
	Class    string   `json:"class"`
	Unit     string   `json:"unit"`
	DType    string   `json:"dtype"`
	Fn       int      `json:"fn"`
	Address  int      `json:"address"`
	Swap     bool     `json:"word_swap"`
	Scale    float64  `json:"scale"`
	Offset   float64  `json:"offset"`
	WarnLow  *float64 `json:"warn_low"`
	WarnHigh *float64 `json:"warn_high"`
	CritLow  *float64 `json:"crit_low"`
	CritHigh *float64 `json:"crit_high"`
}

// Reading hasil baca satu titik.
type Reading struct {
	PointID int64    `json:"point_id"`
	Label   string   `json:"label"`
	Unit    string   `json:"unit"`
	Value   *float64 `json:"value"`
	Raw     []uint16 `json:"raw,omitempty"`
	Status  string   `json:"status"` // ok | warn | crit | "" (gagal)
	Error   string   `json:"error,omitempty"`
}

// Judge status dari ambang titik (batas termasuk).
func Judge(p Point, v float64) string {
	le := func(t *float64) bool { return t != nil && v <= *t }
	ge := func(t *float64) bool { return t != nil && v >= *t }
	switch {
	case le(p.CritLow) || ge(p.CritHigh):
		return "crit"
	case le(p.WarnLow) || ge(p.WarnHigh):
		return "warn"
	}
	return "ok"
}

// ReadAll membaca semua titik satu perangkat dalam satu koneksi.
func ReadAll(ctx context.Context, host string, port, unit int, points []Point, timeout time.Duration) ([]Reading, error) {
	c, err := Dial(ctx, host, port, unit, timeout)
	if err != nil {
		return nil, err
	}
	defer c.Close()
	out := make([]Reading, 0, len(points))
	for _, p := range points {
		r := Reading{PointID: p.ID, Label: p.Label, Unit: p.Unit}
		regs, err := c.Read(byte(p.Fn), uint16(p.Address), Words(p.DType))
		if err == nil {
			var v float64
			if v, err = Decode(regs, p.DType, p.Swap); err == nil {
				v = v*p.Scale + p.Offset
				r.Value, r.Raw, r.Status = &v, regs, Judge(p, v)
			}
		}
		if err != nil {
			r.Error = err.Error()
			if ctx.Err() != nil {
				out = append(out, r)
				return out, ctx.Err()
			}
		}
		out = append(out, r)
	}
	return out, nil
}

// Runner membaca semua perangkat ber-Modbus tiap putaran.
type Runner struct {
	DB     *pgxpool.Pool
	Log    *slog.Logger
	Writer metrics.Writer
	Store  store.Store
}

type target struct {
	id         int64
	name, host string
	port, unit int
	points     []Point
}

// Load perangkat aktif ber-Modbus (collectorID nil = semua) beserta titik aktifnya; onlyID > 0 untuk satu perangkat.
func (r *Runner) load(ctx context.Context, onlyID int64) ([]target, error) {
	return r.loadWhere(ctx, onlyID, false)
}

// loadWhere: central = true hanya perangkat yang dipantau pusat (bukan collector site).
func (r *Runner) loadWhere(ctx context.Context, onlyID int64, central bool) ([]target, error) {
	rows, err := r.DB.Query(ctx, `SELECT d.id, d.name, d.host, d.modbus_port, d.modbus_unit, p.id, p.label, p.class, p.unit, p.fn, p.address, p.dtype,
			p.word_swap, p.scale, p.offset_value, p.warn_low, p.warn_high, p.crit_low, p.crit_high
		FROM devices d JOIN modbus_points p ON p.device_id = d.id AND p.enabled
		WHERE d.enabled AND d.modbus_port IS NOT NULL AND ($1 = 0 OR d.id = $1) AND (NOT $2 OR d.collector_id IS NULL)
		ORDER BY d.id, p.position, p.id`, onlyID, central)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []target
	for rows.Next() {
		var t target
		var p Point
		if err := rows.Scan(&t.id, &t.name, &t.host, &t.port, &t.unit, &p.ID, &p.Label, &p.Class, &p.Unit, &p.Fn, &p.Address, &p.DType,
			&p.Swap, &p.Scale, &p.Offset, &p.WarnLow, &p.WarnHigh, &p.CritLow, &p.CritHigh); err != nil {
			return nil, err
		}
		if n := len(out); n > 0 && out[n-1].id == t.id {
			out[n-1].points = append(out[n-1].points, p)
			continue
		}
		t.points = []Point{p}
		out = append(out, t)
	}
	return out, rows.Err()
}

// Run satu putaran untuk semua perangkat (paralel, maks. 16).
func (r *Runner) Run(ctx context.Context) error {
	ts, err := r.loadWhere(ctx, 0, true)
	if err != nil {
		return err
	}
	var wg sync.WaitGroup
	sem := make(chan struct{}, 16)
	var mu sync.Mutex
	var batch metrics.Batch
	for _, t := range ts {
		wg.Add(1)
		sem <- struct{}{}
		go func(t target) {
			defer wg.Done()
			defer func() { <-sem }()
			res, err := ReadAll(ctx, t.host, t.port, t.unit, t.points, 5*time.Second)
			mu.Lock()
			defer mu.Unlock()
			r.save(ctx, t, res, err, &batch, time.Now())
		}(t)
	}
	wg.Wait()
	if err := r.Writer.Write(ctx, &batch); err != nil {
		r.Log.Warn("metrik Modbus tidak terkirim", "galat", err)
	}
	return nil
}

// Test membaca satu perangkat sekarang (tombol "Uji baca"), tanpa menyimpan.
func (r *Runner) Test(ctx context.Context, deviceID int64) ([]Reading, error) {
	ts, err := r.load(ctx, deviceID)
	if err != nil {
		return nil, err
	}
	if len(ts) == 0 {
		return nil, nil
	}
	return ReadAll(ctx, ts[0].host, ts[0].port, ts[0].unit, ts[0].points, 5*time.Second)
}

var groupOf = map[string]string{"voltage": "daya", "current": "daya", "power": "daya", "battery": "daya", "fuel": "daya", "temperature": "lingkungan"}

// ApplySample menerapkan hasil baca dari collector site: hanya perangkat aktif milik collector itu, dan hanya bila
// lebih baru dari pembacaan terakhir yang tersimpan. applied=false bila dilewati.
func (r *Runner) ApplySample(ctx context.Context, collectorID, deviceID int64, readings []Reading, errText string, at time.Time) (bool, error) {
	var ok bool
	if err := r.DB.QueryRow(ctx, `SELECT EXISTS (SELECT 1 FROM devices WHERE id = $1 AND collector_id = $2 AND enabled
		AND modbus_port IS NOT NULL AND (modbus_at IS NULL OR modbus_at < $3))`, deviceID, collectorID, at).Scan(&ok); err != nil || !ok {
		return false, err
	}
	ts, err := r.load(ctx, deviceID)
	if err != nil || len(ts) == 0 {
		return false, err
	}
	// hanya titik yang masih ada dan aktif di pusat
	known := map[int64]bool{}
	for _, p := range ts[0].points {
		known[p.ID] = true
	}
	var keep []Reading
	for _, rd := range readings {
		if known[rd.PointID] {
			keep = append(keep, rd)
		}
	}
	var batch metrics.Batch
	var rerr error
	if errText != "" {
		rerr = errors.New(errText)
	}
	r.save(ctx, ts[0], keep, rerr, &batch, at)
	_ = r.Writer.Write(ctx, &batch)
	return true, nil
}

func (r *Runner) save(ctx context.Context, t target, res []Reading, err error, batch *metrics.Batch, now time.Time) {
	if err != nil {
		msg := err.Error()
		if len(msg) > 300 {
			msg = msg[:300]
		}
		_, _ = r.DB.Exec(ctx, `UPDATE devices SET modbus_error = $2, modbus_at = $3 WHERE id = $1`, t.id, msg, now)
		r.Log.Warn("baca Modbus gagal", "perangkat", t.name, "galat", msg)
		return // sensor lama dibiarkan menua (tidak dinilai setelah 20 menit)
	}
	byID := map[int64]Point{}
	for _, p := range t.points {
		byID[p.ID] = p
	}
	rows := make([]store.SensorRow, 0, len(res))
	failed := 0
	dev := strconv.FormatInt(t.id, 10)
	for _, rd := range res {
		if rd.Value == nil {
			failed++
			continue
		}
		p := byID[rd.PointID]
		key := "mb:" + strconv.FormatInt(p.ID, 10)
		grp := groupOf[p.Class]
		if grp == "" {
			grp = "sistem"
		}
		rows = append(rows, store.SensorRow{Key: key, Class: p.Class, Group: grp, Label: p.Label, Unit: p.Unit, Value: rd.Value, Status: rd.Status})
		batch.Add("antareja_sensor", map[string]string{"device_id": dev, "key": key, "class": p.Class}, *rd.Value, now)
	}
	if err := r.Store.SaveModbusSensors(ctx, t.id, rows); err != nil {
		r.Log.Error("simpan sensor Modbus gagal", "perangkat", t.name, "galat", err)
	}
	var msg *string
	if failed > 0 {
		s := strconv.Itoa(failed) + " titik gagal dibaca"
		for _, rd := range res {
			if rd.Error != "" {
				s += ", mis. " + rd.Label + ": " + rd.Error
				break
			}
		}
		msg = &s
	}
	_, _ = r.DB.Exec(ctx, `UPDATE devices SET modbus_error = $2, modbus_at = $3 WHERE id = $1`, t.id, msg, now)
}
