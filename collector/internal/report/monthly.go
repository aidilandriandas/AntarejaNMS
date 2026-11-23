package report

import (
	"context"
	"log/slog"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

// MonthStart awal bulan (zona waktu lokal collector) untuk t.
func MonthStart(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), 1, 0, 0, 0, 0, t.Location())
}

// SnapshotMonth menghitung down per perangkat untuk satu bulan dan menyimpannya ke downtime_monthly.
// final=true untuk bulan yang sudah lewat: barisnya tidak dihitung ulang lagi.
func SnapshotMonth(ctx context.Context, db *pgxpool.Pool, month time.Time, final bool) (int, error) {
	start := MonthStart(month)
	end := start.AddDate(0, 1, 0)
	rows, err := db.Query(ctx, availSQL, start, end, int64(0))
	if err != nil {
		return 0, err
	}
	b := &pgx.Batch{}
	n := 0
	for rows.Next() {
		var x DeviceAvail
		if err := rows.Scan(&x.ID, &x.Name, &x.Host, &x.Site, &x.Monitored, &x.Down, &x.Incidents); err != nil {
			rows.Close()
			return 0, err
		}
		if x.Monitored <= 0 {
			continue
		}
		if x.Down > x.Monitored {
			x.Down = x.Monitored
		}
		b.Queue(`INSERT INTO downtime_monthly (month, device_id, device_name, host, site, monitored_s, down_s, outages, final, computed_at)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9, now())
			ON CONFLICT (month, device_id) DO UPDATE SET device_name = EXCLUDED.device_name, host = EXCLUDED.host, site = EXCLUDED.site,
				monitored_s = EXCLUDED.monitored_s, down_s = EXCLUDED.down_s, outages = EXCLUDED.outages, final = EXCLUDED.final, computed_at = now()
			WHERE NOT downtime_monthly.final`, start, x.ID, x.Name, x.Host, x.Site, x.Monitored, x.Down, x.Incidents, final)
		n++
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, err
	}
	if n == 0 {
		return 0, nil
	}
	return n, db.SendBatch(ctx, b).Close()
}

// MonthlyRunner memperbarui rekap: bulan berjalan tiap putaran; bulan lalu dikunci setelah lewat 1 jam (memberi waktu
// problem yang pulih tepat di pergantian bulan tercatat); saat start, bulan-bulan yang belum ada diisi mundur 12 bulan.
type MonthlyRunner struct {
	DB  *pgxpool.Pool
	Log *slog.Logger
	bf  bool
}

func (r *MonthlyRunner) Run(ctx context.Context) error {
	now := time.Now()
	cur := MonthStart(now)
	if !r.bf {
		for i := 12; i >= 1; i-- {
			m := cur.AddDate(0, -i, 0)
			var exists bool
			if err := r.DB.QueryRow(ctx, `SELECT EXISTS (SELECT 1 FROM downtime_monthly WHERE month = $1 AND final)`, m).Scan(&exists); err != nil {
				return err
			}
			if !exists && (i > 1 || now.Sub(cur) > time.Hour) {
				if _, err := SnapshotMonth(ctx, r.DB, m, true); err != nil {
					return err
				}
			}
		}
		r.bf = true
	}
	prev := cur.AddDate(0, -1, 0)
	if now.Sub(cur) > time.Hour {
		var open bool
		if err := r.DB.QueryRow(ctx, `SELECT EXISTS (SELECT 1 FROM downtime_monthly WHERE month = $1 AND NOT final)`, prev).Scan(&open); err != nil {
			return err
		}
		if open {
			if _, err := SnapshotMonth(ctx, r.DB, prev, true); err != nil {
				return err
			}
			r.Log.Info("rekap down bulanan dikunci", "bulan", prev.Format("2006-01"))
		}
	}
	_, err := SnapshotMonth(ctx, r.DB, cur, false)
	return err
}
