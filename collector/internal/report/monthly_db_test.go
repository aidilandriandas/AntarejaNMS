package report

import (
	"context"
	"math"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestSnapshotMonthDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	m := MonthStart(time.Now()).AddDate(0, -1, 0) // bulan lalu: lengkap
	site := dbtest.ID(t, db, `INSERT INTO sites (code, name) VALUES ('PIT-A', 'Pit A') RETURNING id`)
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id, created_at) VALUES ('Radio', '10.0.0.1', $1, $2) RETURNING id`, site, m.AddDate(0, -2, 0))
	t0 := m.Add(24 * time.Hour)
	// down 2 jam, 30 menit di antaranya maintenance -> 90 menit
	dbtest.Exec(t, db, `INSERT INTO problems (device_id, kind, severity, title, started_at, resolved_at) VALUES ($1, 'down', 'high', 'x', $2, $3)`, dev, t0, t0.Add(2*time.Hour))
	dbtest.Exec(t, db, `INSERT INTO maintenances (title, scope, starts_at, ends_at) VALUES ('m', 'all', $1, $2)`, t0, t0.Add(30*time.Minute))
	// perangkat baru di tengah bulan: hanya dipantau sejak dibuat
	half := m.AddDate(0, 0, 10)
	dbtest.ID(t, db, `INSERT INTO devices (name, host, created_at) VALUES ('Baru', '10.0.0.2', $1) RETURNING id`, half)

	n, err := SnapshotMonth(ctx, db, m, true)
	if err != nil || n != 2 {
		t.Fatalf("n=%d err=%v", n, err)
	}
	var down, mon float64
	var outages int
	var siteCode string
	if err := db.QueryRow(ctx, `SELECT down_s, monitored_s, outages, site FROM downtime_monthly WHERE device_id = $1`, dev).Scan(&down, &mon, &outages, &siteCode); err != nil {
		t.Fatal(err)
	}
	full := m.AddDate(0, 1, 0).Sub(m).Seconds()
	if math.Abs(down-5400) > 1 || math.Abs(mon-full) > 1 || outages != 1 || siteCode != "PIT-A" {
		t.Fatalf("down=%v mon=%v (penuh %v) outages=%d site=%s", down, mon, full, outages, siteCode)
	}
	var monBaru float64
	_ = db.QueryRow(ctx, `SELECT monitored_s FROM downtime_monthly WHERE device_name = 'Baru'`).Scan(&monBaru)
	if math.Abs(monBaru-m.AddDate(0, 1, 0).Sub(half).Seconds()) > 1 {
		t.Fatalf("perangkat baru dipantau %v detik", monBaru)
	}

	// bulan yang sudah final tidak berubah walau data problem berubah atau dihapus
	dbtest.Exec(t, db, `DELETE FROM problems`)
	if _, err := SnapshotMonth(ctx, db, m, true); err != nil {
		t.Fatal(err)
	}
	_ = db.QueryRow(ctx, `SELECT down_s FROM downtime_monthly WHERE device_id = $1`, dev).Scan(&down)
	if math.Abs(down-5400) > 1 {
		t.Fatalf("bulan final ikut berubah: %v", down)
	}
	// perangkat dihapus: riwayat tetap ada dengan nama tersimpan
	dbtest.Exec(t, db, `DELETE FROM devices WHERE id = $1`, dev)
	var name string
	if err := db.QueryRow(ctx, `SELECT device_name FROM downtime_monthly WHERE device_id IS NULL`).Scan(&name); err != nil || name != "Radio" {
		t.Fatalf("riwayat hilang: %v %q", err, name)
	}
}
