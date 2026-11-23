package checks

import (
	"context"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestRunnerSimpanDanKejadian(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) }))
	defer srv.Close()
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	closed := ln.Addr().(*net.TCPAddr).Port
	ln.Close()

	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('srv', '127.0.0.1') RETURNING id`)
	web := dbtest.ID(t, db, `INSERT INTO service_checks (name, device_id, type, url, interval_s) VALUES ('web', $1, 'http', $2, 30) RETURNING id`, dev, srv.URL)
	tcp := dbtest.ID(t, db, `INSERT INTO service_checks (name, device_id, type, port, timeout_s, interval_s) VALUES ('db', $1, 'tcp', $2, 2, 30) RETURNING id`, dev, closed)
	off := dbtest.ID(t, db, `INSERT INTO service_checks (name, device_id, type, port, enabled) VALUES ('mati', $1, 'tcp', 1, false) RETURNING id`, dev)
	r := &Runner{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}

	status := func(id int64) (string, int) {
		var s string
		var f int
		_ = db.QueryRow(ctx, `SELECT status, fail_streak FROM service_checks WHERE id = $1`, id).Scan(&s, &f)
		return s, f
	}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	if s, _ := status(web); s != "up" {
		t.Fatalf("web: %s", s)
	}
	if s, f := status(tcp); s != "unknown" || f != 1 {
		t.Fatalf("satu kali gagal: %s %d", s, f)
	}
	if s, _ := status(off); s != "unknown" {
		t.Fatal("cek nonaktif tidak dijalankan")
	}
	// putaran berikut belum jatuh tempo: tidak diulang
	_ = r.Run(ctx)
	if _, f := status(tcp); f != 1 {
		t.Fatalf("belum jatuh tempo, dapat fail_streak %d", f)
	}
	// uji sekarang: gagal kedua → down + kejadian
	res, err := r.RunOne(ctx, tcp)
	if err != nil || res.OK {
		t.Fatalf("%+v %v", res, err)
	}
	if s, _ := status(tcp); s != "down" {
		t.Fatalf("dua kali gagal: %s", s)
	}
	var n int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM events WHERE device_id = $1 AND kind = 'check_down'`, dev).Scan(&n)
	if n != 1 {
		t.Fatalf("kejadian check_down: %d", n)
	}
	// port dibuka: pulih + kejadian check_up
	ln2, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(closed))
	if err != nil {
		t.Skip("port uji sudah dipakai proses lain")
	}
	defer ln2.Close()
	go func() {
		for {
			c, err := ln2.Accept()
			if err != nil {
				return
			}
			c.Close()
		}
	}()
	if res, _ := r.RunOne(ctx, tcp); !res.OK {
		t.Fatalf("port dibuka: %+v", res)
	}
	_ = db.QueryRow(ctx, `SELECT count(*) FROM events WHERE device_id = $1 AND kind = 'check_up'`, dev).Scan(&n)
	if s, _ := status(tcp); s != "up" || n != 1 {
		t.Fatalf("pulih: %s, kejadian %d", s, n)
	}
}
