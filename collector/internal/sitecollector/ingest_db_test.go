package sitecollector

import (
	"context"
	"io"
	"log/slog"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/config"
	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/ping"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

func up() ping.Result   { return ping.Result{Sent: 3, Recv: 3, AvgMS: 2, MinMS: 1, MaxMS: 3} }
func down() ping.Result { return ping.Result{Sent: 3, LossPct: 100} }

func TestIngestUrutanGandaDanKepemilikan(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	cid := dbtest.ID(t, db, `INSERT INTO site_collectors (name, token_hash, token_hint) VALUES ('pit-a', repeat('a', 64), 'acs_x') RETURNING id`)
	other := dbtest.ID(t, db, `INSERT INTO site_collectors (name, token_hash, token_hint) VALUES ('pit-b', repeat('b', 64), 'acs_y') RETURNING id`)
	mine := dbtest.ID(t, db, `INSERT INTO devices (name, host, collector_id, status) VALUES ('sw-a', '10.1.0.1', $1, 'up') RETURNING id`, cid)
	theirs := dbtest.ID(t, db, `INSERT INTO devices (name, host, collector_id, status) VALUES ('sw-b', '10.1.0.2', $1, 'up') RETURNING id`, other)
	central := dbtest.ID(t, db, `INSERT INTO devices (name, host, status) VALUES ('core', '10.1.0.3', 'up') RETURNING id`)

	cfg := config.Config{DownAfter: 2, LatencyHighMS: 150}
	p := poller.New(cfg, store.Store{DB: db}, nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	after := 0
	p.AfterPing = func(context.Context) { after++ }

	// Jalur putus 10.00 s.d. 10.03: tiga putaran diantrekan di site, perangkat sempat down lalu pulih.
	base := time.Now().Add(-10 * time.Minute).Truncate(time.Minute)
	b := Batch{SentAt: time.Now()}
	for i, r := range []ping.Result{down(), down(), down(), up()} {
		at := base.Add(time.Duration(i) * time.Minute)
		b.Pings = append(b.Pings, poller.PingSample{DeviceID: mine, At: at, Result: r},
			poller.PingSample{DeviceID: theirs, At: at, Result: down()}, poller.PingSample{DeviceID: central, At: at, Result: down()})
	}
	res, err := Ingest(ctx, p, cid, b)
	if err != nil {
		t.Fatal(err)
	}
	if res.Pings != 4 || res.Skipped != 8 {
		t.Fatalf("hanya perangkat milik collector yang diterapkan: %+v", res)
	}
	var st string
	_ = db.QueryRow(ctx, `SELECT status FROM devices WHERE id = $1`, mine).Scan(&st)
	if st != "up" {
		t.Fatalf("status akhir up, dapat %s", st)
	}
	var downAt, upAt time.Time
	if err := db.QueryRow(ctx, `SELECT (SELECT at FROM events WHERE device_id = $1 AND kind = 'down'), (SELECT at FROM events WHERE device_id = $1 AND kind = 'up')`, mine).Scan(&downAt, &upAt); err != nil {
		t.Fatalf("kejadian down/up dari antrean harus tercatat: %v", err)
	}
	if !downAt.Equal(base.Add(time.Minute)) || !upAt.Equal(base.Add(3*time.Minute)) {
		t.Fatalf("waktu kejadian mengikuti waktu pengukuran di site: down %s up %s (dasar %s)", downAt, upAt, base)
	}
	for _, id := range []int64{theirs, central} {
		_ = db.QueryRow(ctx, `SELECT status FROM devices WHERE id = $1`, id).Scan(&st)
		if st != "up" {
			t.Fatalf("perangkat %d bukan milik collector ini, tidak boleh berubah (%s)", id, st)
		}
	}
	if after != 1 {
		t.Fatalf("penilaian problem dijalankan sekali per kiriman, dapat %d", after)
	}

	// Kiriman ulang yang sama (jawaban sebelumnya hilang): dilewati semua, hitungan gagal tidak ganda.
	res, err = Ingest(ctx, p, cid, b)
	if err != nil || res.Pings != 0 {
		t.Fatalf("kiriman ganda harus dilewati: %+v %v", res, err)
	}
	var n int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM events WHERE device_id = $1 AND kind = 'down'`, mine).Scan(&n)
	if n != 1 {
		t.Fatalf("kejadian down tidak boleh ganda, dapat %d", n)
	}

	// SNMP: sampel milik collector diterapkan, sampel lebih lama dari yang tersimpan dilewati.
	dbtest.Exec(t, db, `UPDATE devices SET snmp_enabled = true WHERE id = $1`, mine)
	now := time.Now().Truncate(time.Second)
	sys := &snmp.System{Name: "sw-a-site", ObjectID: ".1.3.6.1.4.1.9", UptimeTicks: 360000}
	res, err = Ingest(ctx, p, cid, Batch{SNMP: []poller.SNMPSample{{DeviceID: mine, At: now, Sys: sys}, {DeviceID: theirs, At: now, Sys: sys}}})
	if err != nil || res.SNMP != 1 {
		t.Fatalf("SNMP: %+v %v", res, err)
	}
	var name string
	_ = db.QueryRow(ctx, `SELECT sys_name FROM devices WHERE id = $1`, mine).Scan(&name)
	if name != "sw-a-site" {
		t.Fatalf("sys_name tersimpan, dapat %q", name)
	}
	res, _ = Ingest(ctx, p, cid, Batch{SNMP: []poller.SNMPSample{{DeviceID: mine, At: now.Add(-time.Minute), Sys: sys}}})
	if res.SNMP != 0 {
		t.Fatal("sampel SNMP lebih lama dari yang tersimpan harus dilewati")
	}
}

func TestIngestKoreksiJamSite(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	cid := dbtest.ID(t, db, `INSERT INTO site_collectors (name, token_hash, token_hint) VALUES ('pit-c', repeat('c', 64), 'acs_z') RETURNING id`)
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, collector_id) VALUES ('ap', '10.2.0.1', $1) RETURNING id`, cid)
	p := poller.New(config.Config{DownAfter: 2, LatencyHighMS: 150}, store.Store{DB: db}, nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	// Jam site terlambat 2 jam: pengukuran "1 menit lalu" menurut jam site.
	skew := -2 * time.Hour
	siteNow := time.Now().Add(skew)
	res, err := Ingest(ctx, p, cid, Batch{SentAt: siteNow, Pings: []poller.PingSample{{DeviceID: dev, At: siteNow.Add(-time.Minute), Result: up()}}})
	if err != nil || res.Pings != 1 {
		t.Fatalf("%+v %v", res, err)
	}
	if res.SkewSecs < 7190 || res.SkewSecs > 7210 {
		t.Fatalf("selisih jam terbaca sekitar 7200 detik, dapat %d", res.SkewSecs)
	}
	var at time.Time
	_ = db.QueryRow(ctx, `SELECT last_ping_at FROM site_collectors WHERE id = $1`, cid).Scan(&at)
	if d := time.Since(at); d < 50*time.Second || d > 70*time.Second {
		t.Fatalf("waktu sampel dikoreksi ke jam pusat (sekitar 1 menit lalu), dapat %s lalu", d)
	}
}
