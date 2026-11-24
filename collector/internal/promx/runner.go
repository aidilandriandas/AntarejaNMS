package promx

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/metrics"
	"github.com/aidilandriandas/antarejanms/collector/internal/profiles"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

const (
	maxBody = 16 << 20
	timeout = 10 * time.Second
)

// CheckURL: hanya http/https, tanpa nama pengguna/kata sandi di URL (kredensial tidak disimpan di database).
func CheckURL(raw string) error {
	u, err := url.Parse(raw)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return errors.New("URL exporter harus http:// atau https://")
	}
	if u.User != nil {
		return errors.New("URL exporter tidak boleh berisi nama pengguna atau kata sandi")
	}
	return nil
}

// Tanpa proxy: exporter ada di jaringan manajemen yang dijangkau langsung oleh collector.
var client = &http.Client{Timeout: timeout, Transport: &http.Transport{Proxy: nil, MaxIdleConnsPerHost: 1, IdleConnTimeout: 90 * time.Second,
	TLSHandshakeTimeout: timeout, ResponseHeaderTimeout: timeout}, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}

// Scrape mengambil /metrics dan menyimpan hanya metrik yang dipakai.
func Scrape(ctx context.Context, rawURL string) ([]Sample, error) {
	if err := CheckURL(rawURL); err != nil {
		return nil, err
	}
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "text/plain;version=0.0.4")
	req.Header.Set("User-Agent", "antareja-collector")
	res, err := client.Do(req)
	if err != nil {
		return nil, friendly(err)
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("exporter menjawab HTTP %d", res.StatusCode)
	}
	if ct := res.Header.Get("Content-Type"); strings.Contains(ct, "protobuf") {
		return nil, errors.New("exporter mengirim format protobuf, bukan teks")
	}
	s, err := Parse(io.LimitReader(res.Body, maxBody), Keep)
	if err != nil && !errors.Is(err, errTooMany) {
		return nil, err
	}
	return s, nil
}

func friendly(err error) error {
	m := err.Error()
	switch {
	case strings.Contains(m, "connection refused"):
		return errors.New("koneksi ditolak (exporter tidak jalan atau port salah)")
	case strings.Contains(m, "deadline exceeded"), strings.Contains(m, "Timeout"):
		return errors.New("tidak menjawab dalam 10 detik")
	case strings.Contains(m, "certificate"):
		return errors.New("sertifikat TLS exporter tidak dipercaya")
	}
	return err
}

// Measure satu perangkat: scrape lalu hitung sensor. state menyimpan counter CPU per perangkat antarputaran.
func Measure(ctx context.Context, st *States, deviceID int64, rawURL string, services []string) ([]Reading, string, error) {
	s, err := Scrape(ctx, rawURL)
	if err != nil {
		return nil, "", err
	}
	out, next, kind := Compute(s, services, st.get(deviceID))
	if kind == "" {
		return nil, "", errors.New("tidak ada metrik node_exporter atau windows_exporter di URL ini")
	}
	st.put(deviceID, next)
	return out, kind, nil
}

// States counter CPU per perangkat (aman dipakai paralel).
type States struct {
	mu sync.Mutex
	m  map[int64]*CPUState
}

func (s *States) get(id int64) *CPUState {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.m[id]
}

func (s *States) put(id int64, c *CPUState) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.m == nil {
		s.m = map[int64]*CPUState{}
	}
	if c == nil {
		delete(s.m, id)
		return
	}
	s.m[id] = c
}

// Services memecah daftar layanan dari kolom exporter_services.
func Services(raw *string) []string {
	if raw == nil {
		return nil
	}
	var out []string
	for _, p := range strings.FieldsFunc(*raw, func(r rune) bool { return r == ',' || r == '\n' }) {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

// Runner scrape semua perangkat ber-exporter milik pusat tiap menit.
type Runner struct {
	DB     *pgxpool.Pool
	Log    *slog.Logger
	Writer metrics.Writer
	Store  store.Store

	states States
}

type target struct {
	id         int64
	name, url  string
	services   []string
	thresholds []byte
}

func (r *Runner) load(ctx context.Context, onlyID int64, central bool) ([]target, error) {
	rows, err := r.DB.Query(ctx, `SELECT id, name, exporter_url, exporter_services, (COALESCE((SELECT t.thresholds FROM device_templates t WHERE t.id = devices.template_id), '{}'::jsonb) || devices.thresholds) FROM devices
		WHERE enabled AND exporter_url IS NOT NULL AND ($1 = 0 OR id = $1) AND (NOT $2 OR collector_id IS NULL) ORDER BY id`, onlyID, central)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []target
	for rows.Next() {
		var t target
		var svc *string
		if err := rows.Scan(&t.id, &t.name, &t.url, &svc, &t.thresholds); err != nil {
			return nil, err
		}
		t.services = Services(svc)
		out = append(out, t)
	}
	return out, rows.Err()
}

// Run satu putaran (paralel, maks. 16).
func (r *Runner) Run(ctx context.Context) error {
	ts, err := r.load(ctx, 0, true)
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
			res, _, err := Measure(ctx, &r.states, t.id, t.url, t.services)
			mu.Lock()
			defer mu.Unlock()
			r.save(ctx, t, res, err, &batch, time.Now())
		}(t)
	}
	wg.Wait()
	if err := r.Writer.Write(ctx, &batch); err != nil {
		r.Log.Warn("metrik exporter tidak terkirim", "galat", err)
	}
	return nil
}

// Test scrape satu perangkat sekarang tanpa menyimpan (tombol "Uji scrape"). CPU baru terbaca di putaran kedua.
func (r *Runner) Test(ctx context.Context, deviceID int64) ([]Reading, string, error) {
	ts, err := r.load(ctx, deviceID, false)
	if err != nil {
		return nil, "", err
	}
	if len(ts) == 0 {
		return nil, "", errors.New("perangkat tidak punya URL exporter")
	}
	var tmp States
	return Measure(ctx, &tmp, deviceID, ts[0].url, ts[0].services)
}

// ApplySample hasil dari collector site: hanya perangkat aktif milik collector itu, dan hanya bila lebih baru.
func (r *Runner) ApplySample(ctx context.Context, collectorID, deviceID int64, readings []Reading, errText string, at time.Time) (bool, error) {
	var ok bool
	if err := r.DB.QueryRow(ctx, `SELECT EXISTS (SELECT 1 FROM devices WHERE id = $1 AND collector_id = $2 AND enabled
		AND exporter_url IS NOT NULL AND (exporter_at IS NULL OR exporter_at < $3))`, deviceID, collectorID, at).Scan(&ok); err != nil || !ok {
		return false, err
	}
	ts, err := r.load(ctx, deviceID, false)
	if err != nil || len(ts) == 0 {
		return false, err
	}
	var rerr error
	if errText != "" {
		rerr = errors.New(errText)
	}
	var batch metrics.Batch
	r.save(ctx, ts[0], readings, rerr, &batch, at)
	_ = r.Writer.Write(ctx, &batch)
	return true, nil
}

func (r *Runner) save(ctx context.Context, t target, res []Reading, err error, batch *metrics.Batch, now time.Time) {
	if err != nil {
		msg := err.Error()
		if len(msg) > 300 {
			msg = msg[:300]
		}
		_, _ = r.DB.Exec(ctx, `UPDATE devices SET exporter_error = $2, exporter_at = $3 WHERE id = $1`, t.id, msg, now)
		r.Log.Warn("scrape exporter gagal", "perangkat", t.name, "galat", msg)
		return // sensor lama dibiarkan menua
	}
	ov := store.ParseLimits(t.thresholds).Classes
	dev := strconv.FormatInt(t.id, 10)
	rows := make([]store.SensorRow, 0, len(res))
	for _, rd := range res {
		if !strings.HasPrefix(rd.Key, "px:") || len(rows) >= 200 {
			continue
		}
		row := store.SensorRow{Key: rd.Key, Class: rd.Class, Group: "sistem", Label: rd.Label, Unit: rd.Unit, Text: rd.Text, Status: rd.Status, Value: rd.Value}
		if rd.Value != nil {
			if math.IsNaN(*rd.Value) || math.IsInf(*rd.Value, 0) {
				continue
			}
			pr := profiles.Reading{Class: rd.Class, Label: rd.Label, Value: *rd.Value}
			profiles.JudgeWith(&pr, ov)
			row.Status = pr.Status
			batch.Add("antareja_sensor", map[string]string{"device_id": dev, "key": rd.Key, "class": rd.Class}, *rd.Value, now)
		}
		if row.Status == "" {
			row.Status = "ok"
		}
		rows = append(rows, row)
	}
	if err := r.Store.SaveExporterSensors(ctx, t.id, rows); err != nil {
		r.Log.Error("simpan sensor exporter gagal", "perangkat", t.name, "galat", err)
	}
	_, _ = r.DB.Exec(ctx, `UPDATE devices SET exporter_error = NULL, exporter_at = $2 WHERE id = $1`, t.id, now)
}
