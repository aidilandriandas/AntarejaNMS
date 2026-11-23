package checks

import (
	"context"
	"fmt"
	"log/slog"
	"strconv"
	"sync"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/metrics"
)

// failsForDown percobaan gagal berturut-turut sebelum cek dinyatakan down (sama dengan status perangkat).
const failsForDown = 2

// Runner menjalankan cek yang jatuh tempo.
type Runner struct {
	DB     *pgxpool.Pool
	Log    *slog.Logger
	Writer metrics.Writer

	mu sync.Mutex // satu putaran pada satu waktu (terjadwal atau "Uji sekarang")
}

type row struct {
	Spec
	Name, Device string
	DeviceID     int64
	Status       string
	Fails        int
	SlowMS       *int
}

const selectCols = `c.id, c.type, d.host, COALESCE(c.url, ''), COALESCE(c.port, 0), COALESCE(c.query, ''), COALESCE(c.expect_status, ''),
	COALESCE(c.expect_text, ''), COALESCE(c.expect_answer, ''), c.verify_tls, c.timeout_s, c.name, d.name, d.id, c.status, c.fail_streak, c.slow_ms`

func scan(r interface{ Scan(...any) error }) (row, error) {
	var x row
	var timeout int
	err := r.Scan(&x.ID, &x.Type, &x.Host, &x.URL, &x.Port, &x.Query, &x.ExpectStatus, &x.ExpectText, &x.ExpectAnswer, &x.VerifyTLS,
		&timeout, &x.Name, &x.Device, &x.DeviceID, &x.Status, &x.Fails, &x.SlowMS)
	x.Timeout = time.Duration(timeout) * time.Second
	return x, err
}

// Run menjalankan semua cek aktif yang jatuh tempo (perangkat aktif saja).
func (r *Runner) Run(ctx context.Context) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	rows, err := r.DB.Query(ctx, `SELECT `+selectCols+` FROM service_checks c JOIN devices d ON d.id = c.device_id
		WHERE c.enabled AND d.enabled AND (c.last_at IS NULL OR c.last_at <= now() - make_interval(secs => c.interval_s - 5))
		ORDER BY c.last_at NULLS FIRST LIMIT 500`)
	if err != nil {
		return err
	}
	var due []row
	for rows.Next() {
		x, err := scan(rows)
		if err != nil {
			rows.Close()
			return err
		}
		due = append(due, x)
	}
	rows.Close()
	r.runAll(ctx, due)
	return nil
}

// RunOne menjalankan satu cek sekarang (tombol "Uji sekarang") dan menyimpan hasilnya.
func (r *Runner) RunOne(ctx context.Context, id int64) (Result, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	x, err := scan(r.DB.QueryRow(ctx, `SELECT `+selectCols+` FROM service_checks c JOIN devices d ON d.id = c.device_id WHERE c.id = $1`, id))
	if err != nil {
		return Result{}, err
	}
	res := Run(ctx, x.Spec)
	var batch metrics.Batch
	r.save(ctx, x, res, &batch)
	_ = r.Writer.Write(ctx, &batch)
	return res, nil
}

func (r *Runner) runAll(ctx context.Context, due []row) {
	if len(due) == 0 {
		return
	}
	jobs := make(chan row)
	var wg sync.WaitGroup
	var mu sync.Mutex
	var batch metrics.Batch
	for i := 0; i < min(32, len(due)); i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for x := range jobs {
				res := Run(ctx, x.Spec)
				mu.Lock()
				r.save(ctx, x, res, &batch)
				mu.Unlock()
			}
		}()
	}
	for _, x := range due {
		jobs <- x
	}
	close(jobs)
	wg.Wait()
	if err := r.Writer.Write(ctx, &batch); err != nil {
		r.Log.Warn("metrik cek layanan tidak terkirim", "galat", err)
	}
}

// Judge status baru dari status lama, jumlah gagal berturut-turut, dan hasil terakhir.
func Judge(prev string, fails int, res Result, slowMS *int) (status string, newFails int) {
	if !res.OK {
		newFails = fails + 1
		if newFails >= failsForDown {
			return "down", newFails
		}
		if prev == "unknown" {
			return "unknown", newFails
		}
		return prev, newFails // satu kali gagal belum mengubah status
	}
	if slowMS != nil && res.MS > float64(*slowMS) {
		return "slow", 0
	}
	return "up", 0
}

func (r *Runner) save(ctx context.Context, x row, res Result, batch *metrics.Batch) {
	status, fails := Judge(x.Status, x.Fails, res, x.SlowMS)
	now := time.Now()
	var errText *string
	if res.Error != "" {
		e := trim(res.Error, 300)
		errText = &e
	}
	ms := res.MS
	_, err := r.DB.Exec(ctx, `UPDATE service_checks SET status = $2::varchar, fail_streak = $3, last_ms = $4, last_error = $5, last_at = $6::timestamptz,
		status_changed_at = CASE WHEN status <> $2::varchar THEN $6::timestamptz ELSE status_changed_at END,
		cert_expires_at = COALESCE($7::timestamptz, cert_expires_at) WHERE id = $1`, x.ID, status, fails, ms, errText, now, res.CertExpiry)
	if err != nil {
		r.Log.Error("simpan hasil cek gagal", "cek", x.ID, "galat", err)
		return
	}
	l := map[string]string{"check_id": strconv.FormatInt(x.ID, 10), "device_id": strconv.FormatInt(x.DeviceID, 10)}
	up := 0.0
	if res.OK {
		up = 1
		batch.Add("antareja_check_ms", l, ms, now)
	}
	batch.Add("antareja_check_up", l, up, now)

	if status != x.Status && x.Status != "" {
		kind, msg := "", ""
		switch {
		case status == "down":
			kind, msg = "check_down", fmt.Sprintf("Cek %s (%s) gagal: %s", x.Name, x.Device, res.Error)
		case x.Status == "down" && status != "unknown":
			kind, msg = "check_up", fmt.Sprintf("Cek %s (%s) kembali normal, %.0f ms", x.Name, x.Device, ms)
		case status == "slow":
			kind, msg = "check_slow", fmt.Sprintf("Cek %s (%s) lambat: %.0f ms (ambang %d ms)", x.Name, x.Device, ms, *x.SlowMS)
		}
		if kind != "" {
			if _, err := r.DB.Exec(ctx, `INSERT INTO events (device_id, kind, message, at) VALUES ($1, $2, $3, $4)`, x.DeviceID, kind, trim(msg, 500), now); err != nil {
				r.Log.Warn("catat kejadian cek gagal", "cek", x.ID, "galat", err)
			}
			r.Log.Info("cek layanan", "cek", x.Name, "status", status, "galat", res.Error)
		}
	}
}
