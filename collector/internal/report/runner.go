package report

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/notify"
)

// Runner mengirim laporan terjadwal. Dipanggil tiap menit.
type Runner struct {
	DB      *pgxpool.Pool
	Notify  *notify.Dispatcher
	Log     *slog.Logger
	BaseURL string
}

type schedule struct {
	ID        int64
	Name      string
	Freq      string
	Hour      int
	ChannelID int64
	SiteID    int64
	Requested bool
	LastKey   string
}

// Run memeriksa jadwal yang jatuh tempo (atau diminta "kirim sekarang") dan mengirim laporannya.
func (r *Runner) Run(ctx context.Context) error {
	rows, err := r.DB.Query(ctx, `SELECT id, name, frequency, send_hour, channel_id, COALESCE(site_id, 0), requested, COALESCE(last_key, '')
		FROM report_schedules WHERE enabled OR requested`)
	if err != nil {
		return err
	}
	var ss []schedule
	for rows.Next() {
		var s schedule
		if rows.Scan(&s.ID, &s.Name, &s.Freq, &s.Hour, &s.ChannelID, &s.SiteID, &s.Requested, &s.LastKey) == nil {
			ss = append(ss, s)
		}
	}
	rows.Close()
	now := time.Now()
	for _, s := range ss {
		p, sendAt, err := LastFull(s.Freq, now, s.Hour)
		if err != nil {
			continue
		}
		due := now.After(sendAt) && p.Key != s.LastKey
		if !due && !s.Requested {
			continue
		}
		err = r.send(ctx, s, p)
		if err != nil {
			msg := err.Error()
			if len(msg) > 250 {
				msg = msg[:250]
			}
			// Gagal terjadwal: coba lagi di putaran berikutnya (paling cepat 15 menit) dengan menunda via last_sent_at
			_, _ = r.DB.Exec(ctx, `UPDATE report_schedules SET last_error = $2, requested = false, last_sent_at = now() WHERE id = $1`, s.ID, msg)
			r.Log.Warn("laporan gagal dikirim", "jadwal", s.ID, "galat", msg)
			continue
		}
		key := s.LastKey
		if due {
			key = p.Key
		}
		_, _ = r.DB.Exec(ctx, `UPDATE report_schedules SET last_error = NULL, requested = false, last_sent_at = now(), last_key = $2 WHERE id = $1`, s.ID, key)
		r.Log.Info("laporan terkirim", "jadwal", s.ID, "periode", p.Key)
	}
	return nil
}

func (r *Runner) send(ctx context.Context, s schedule, p Period) error {
	// Hindari mengulang kegagalan tiap menit: jeda 15 menit setelah gagal
	var lastErr *string
	var lastAt *time.Time
	_ = r.DB.QueryRow(ctx, `SELECT last_error, last_sent_at FROM report_schedules WHERE id = $1`, s.ID).Scan(&lastErr, &lastAt)
	if !s.Requested && lastErr != nil && lastAt != nil && time.Since(*lastAt) < 15*time.Minute {
		return fmt.Errorf("%s", *lastErr)
	}
	m, err := r.Message(ctx, s.Freq, s.SiteID, p)
	if err != nil {
		return err
	}
	return r.Notify.SendTo(ctx, s.ChannelID, m)
}

// Message menyusun pesan laporan untuk semua jenis kanal.
func (r *Runner) Message(ctx context.Context, freq string, siteID int64, p Period) (notify.Message, error) {
	d, err := Build(ctx, r.DB, p, siteID)
	if err != nil {
		return notify.Message{}, fmt.Errorf("menyusun laporan gagal: %w", err)
	}
	d.BaseURL = r.BaseURL
	h, err := HTML(freq, d)
	if err != nil {
		return notify.Message{}, err
	}
	return notify.Message{Event: "report", Subject: Subject(freq, d), Text: Text(freq, d), HTML: Telegram(freq, d), EmailHTML: h}, nil
}

// Preview menyusun laporan untuk satu jadwal (periode penuh terakhir) tanpa mengirim.
func (r *Runner) Preview(ctx context.Context, scheduleID int64) (subject, html string, err error) {
	var freq string
	var site int64
	var hour int
	if err := r.DB.QueryRow(ctx, `SELECT frequency, COALESCE(site_id, 0), send_hour FROM report_schedules WHERE id = $1`, scheduleID).Scan(&freq, &site, &hour); err != nil {
		return "", "", fmt.Errorf("jadwal tidak ditemukan")
	}
	p, _, err := LastFull(freq, time.Now(), hour)
	if err != nil {
		return "", "", err
	}
	m, err := r.Message(ctx, freq, site, p)
	return m.Subject, m.EmailHTML, err
}
