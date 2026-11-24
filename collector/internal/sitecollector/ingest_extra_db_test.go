package sitecollector

import (
	"context"
	"io"
	"log/slog"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/checks"
	"github.com/aidilandriandas/antarejanms/collector/internal/config"
	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/modbus"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

func TestIngestCekDanModbus(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	cid := dbtest.ID(t, db, `INSERT INTO site_collectors (name, token_hash, token_hint) VALUES ('pit', repeat('d', 64), 'acs_d') RETURNING id`)
	other := dbtest.ID(t, db, `INSERT INTO site_collectors (name, token_hash, token_hint) VALUES ('lain', repeat('e', 64), 'acs_e') RETURNING id`)
	srv := dbtest.ID(t, db, `INSERT INTO devices (name, host, collector_id, modbus_port) VALUES ('srv-site', '10.5.0.1', $1, 502) RETURNING id`, cid)
	foreign := dbtest.ID(t, db, `INSERT INTO devices (name, host, collector_id) VALUES ('srv-lain', '10.5.0.2', $1) RETURNING id`, other)
	chk := dbtest.ID(t, db, `INSERT INTO service_checks (name, device_id, type, port) VALUES ('db', $1, 'tcp', 3306) RETURNING id`, srv)
	chkForeign := dbtest.ID(t, db, `INSERT INTO service_checks (name, device_id, type, port) VALUES ('db', $1, 'tcp', 3306) RETURNING id`, foreign)
	pt := dbtest.ID(t, db, `INSERT INTO modbus_points (device_id, label, class, fn, address, scale, warn_low) VALUES ($1, 'Baterai', 'voltage', 4, 1, 0.01, 12.2) RETURNING id`, srv)

	p := poller.New(config.Config{DownAfter: 2}, store.Store{DB: db}, nil, log)
	ap := Appliers{Poller: p, Checks: &checks.Runner{DB: db, Log: log}, Modbus: &modbus.Runner{DB: db, Log: log, Store: store.Store{DB: db}}}

	// pusat tidak menjalankan cek/Modbus perangkat site
	if err := ap.Checks.Run(ctx); err != nil {
		t.Fatal(err)
	}
	var last *time.Time
	_ = db.QueryRow(ctx, `SELECT last_at FROM service_checks WHERE id = $1`, chk).Scan(&last)
	if last != nil {
		t.Fatal("cek perangkat collector site tidak dijalankan dari pusat")
	}

	at := time.Now().Add(-2 * time.Minute).Truncate(time.Second)
	v := 11.9
	b := Batch{SentAt: time.Now(),
		Checks: []CheckSample{{CheckID: chk, At: at, Result: checks.Result{Error: "koneksi ditolak"}}, {CheckID: chk, At: at.Add(time.Minute), Result: checks.Result{Error: "koneksi ditolak"}},
			{CheckID: chkForeign, At: at, Result: checks.Result{OK: true}}},
		Modbus: []ModbusSample{{DeviceID: srv, At: at, Readings: []modbus.Reading{{PointID: pt, Value: &v, Status: "warn"}, {PointID: 99999, Value: &v}}},
			{DeviceID: foreign, At: at}}}
	res, err := IngestAll(ctx, ap, cid, b)
	if err != nil {
		t.Fatal(err)
	}
	if res.Checks != 2 || res.Modbus != 1 || res.Skipped != 2 {
		t.Fatalf("hanya milik collector ini: %+v", res)
	}
	var st string
	_ = db.QueryRow(ctx, `SELECT status FROM service_checks WHERE id = $1`, chk).Scan(&st)
	if st != "down" {
		t.Fatalf("dua gagal berurutan dari antrean → down, dapat %s", st)
	}
	var sv float64
	var sst string
	if err := db.QueryRow(ctx, `SELECT value, status FROM device_sensors WHERE device_id = $1 AND key = 'mb:' || $2::bigint`, srv, pt).Scan(&sv, &sst); err != nil || sv != 11.9 || sst != "warn" {
		t.Fatalf("sensor Modbus dari site: %v %s %v", sv, sst, err)
	}
	// kiriman ulang yang sama dilewati
	res, _ = IngestAll(ctx, ap, cid, b)
	if res.Checks != 0 || res.Modbus != 0 {
		t.Fatalf("kiriman ganda dilewati: %+v", res)
	}
	var ev int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM events WHERE device_id = $1 AND kind = 'check_down'`, srv).Scan(&ev)
	if ev != 1 {
		t.Fatalf("satu kejadian check_down, dapat %d", ev)
	}
}
