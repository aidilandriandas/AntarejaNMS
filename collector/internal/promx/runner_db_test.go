package promx

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

func TestRunnerDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	idle, user := "1000", "100"
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, strings.NewReplacer("%IDLE%", idle, "%USER%", user).Replace(nodeText))
	}))
	defer srv.Close()
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, exporter_url, exporter_services, thresholds)
		VALUES ('srv-app', '127.0.0.1', $1, 'nginx, mysql', '{"disk":[90,98]}') RETURNING id`, srv.URL+"/metrics")
	st := store.Store{DB: db}
	if err := st.SaveSensors(ctx, dev, []store.SensorRow{{Key: "cpu", Class: "cpu", Label: "CPU", Status: "ok"}}); err != nil {
		t.Fatal(err)
	}
	r := &Runner{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Store: st}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	idle, user = "1060", "140"
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	got := map[string]string{}
	rows, _ := db.Query(ctx, `SELECT key, status FROM device_sensors WHERE device_id = $1`, dev)
	for rows.Next() {
		var k, s string
		_ = rows.Scan(&k, &s)
		got[k] = s
	}
	rows.Close()
	// disk 95% dengan ambang khusus 90/98 = warn; CPU 40% ok; mysql gagal = crit
	if got["px:cpu"] != "ok" || got["px:disk:root"] != "warn" || got["px:svc:mysql"] != "crit" || got["px:mem"] != "ok" {
		t.Fatalf("%v", got)
	}
	// putaran SNMP tidak menghapus sensor exporter
	if err := st.SaveSensors(ctx, dev, []store.SensorRow{{Key: "cpu", Class: "cpu", Label: "CPU", Status: "ok"}}); err != nil {
		t.Fatal(err)
	}
	var n int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM device_sensors WHERE device_id = $1 AND key LIKE 'px:%'`, dev).Scan(&n)
	if n != 5 {
		t.Fatalf("sensor exporter: %d", n)
	}
	// exporter mati: galat tercatat, sensor lama dibiarkan
	srv.Close()
	_ = r.Run(ctx)
	var e *string
	_ = db.QueryRow(ctx, `SELECT exporter_error FROM devices WHERE id = $1`, dev).Scan(&e)
	if e == nil || !strings.Contains(*e, "ditolak") {
		t.Fatalf("galat: %v", e)
	}
}

func TestApplySampleSiteDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	cid := dbtest.ID(t, db, `INSERT INTO site_collectors (name, token_hash, token_hint) VALUES ('pit', repeat('f', 64), 'acs_f') RETURNING id`)
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, collector_id, exporter_url) VALUES ('srv-pit', '10.6.0.1', $1, 'http://10.6.0.1:9100/metrics') RETURNING id`, cid)
	r := &Runner{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Store: store.Store{DB: db}}
	v := 97.0
	at := time.Now().Add(-time.Minute).Truncate(time.Second)
	ok, err := r.ApplySample(ctx, cid, dev, []Reading{{Key: "px:mem", Class: "memory", Label: "Memori", Unit: "%", Value: &v}}, "", at)
	if err != nil || !ok {
		t.Fatal(ok, err)
	}
	var st string
	_ = db.QueryRow(ctx, `SELECT status FROM device_sensors WHERE device_id = $1 AND key = 'px:mem'`, dev).Scan(&st)
	if st != "crit" {
		t.Fatalf("memori 97%% dinilai pusat: %s", st)
	}
	if ok, _ := r.ApplySample(ctx, cid, dev, nil, "", at.Add(-time.Second)); ok {
		t.Fatal("sampel lebih lama dilewati")
	}
	if ok, _ := r.ApplySample(ctx, cid+999, dev, nil, "", at.Add(time.Minute)); ok {
		t.Fatal("collector lain ditolak")
	}
}
