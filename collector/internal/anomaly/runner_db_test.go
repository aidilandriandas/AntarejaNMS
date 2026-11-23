package anomaly

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

// VictoriaMetrics palsu: nilai "sekarang" untuk kueri pada waktu kini, nilai riwayat untuk waktu lampau.
func fakeVM(dev int64, latNow, latHist, inNow, inHist float64) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		ts, _ := strconv.ParseInt(q.Get("time"), 10, 64)
		now := time.Since(time.Unix(ts, 0)) < time.Hour
		d := strconv.FormatInt(dev, 10)
		var res []map[string]any
		switch {
		case strings.Contains(q.Get("query"), "rtt"):
			v := latHist
			if now {
				v = latNow
			}
			res = append(res, map[string]any{"metric": map[string]string{"device_id": d}, "value": []any{ts, strconv.FormatFloat(v, 'f', -1, 64)}})
		case strings.Contains(q.Get("query"), "in_octets"):
			v := inHist
			if now {
				v = inNow
			}
			res = append(res, map[string]any{"metric": map[string]string{"device_id": d, "if_index": "1", "if_name": "eth0"}, "value": []any{ts, strconv.FormatFloat(v, 'f', -1, 64)}})
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"status": "success", "data": map[string]any{"resultType": "vector", "result": res}})
	}))
}

func TestRunnerDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, status) VALUES ('cam', '10.0.0.9', 'up') RETURNING id`)
	dbtest.Exec(t, db, `INSERT INTO interfaces (device_id, if_index, name, admin_up, oper_up) VALUES ($1, 1, 'eth0', true, true)`, dev)
	vm := fakeVM(dev, 90, 20, 0, 40e6)
	defer vm.Close()
	r := &Runner{DB: db, VMURL: vm.URL, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
	count := func(q string) int {
		var n int
		_ = db.QueryRow(ctx, q).Scan(&n)
		return n
	}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	if n := count(`SELECT count(*) FROM anomalies`); n != 0 {
		t.Fatalf("putaran pertama belum boleh mencatat, dapat %d", n)
	}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	if n := count(`SELECT count(*) FROM anomalies WHERE ended_at IS NULL`); n != 2 {
		t.Fatalf("latensi naik dan trafik anjlok harus tercatat, dapat %d", n)
	}
	if n := count(`SELECT count(*) FROM events WHERE kind = 'anomaly'`); n != 2 {
		t.Fatalf("kejadian %d", n)
	}
	// tandai wajar (abaikan) latensi: putaran berikutnya menutup, tidak membuka lagi
	dbtest.Exec(t, db, `INSERT INTO anomaly_mutes (device_id, metric, if_index, until) VALUES ($1, 'latency', -1, now() + interval '1 hour')`, dev)
	_ = r.Run(ctx)
	_ = r.Run(ctx)
	if n := count(`SELECT count(*) FROM anomalies WHERE metric = 'latency' AND ended_at IS NULL`); n != 0 {
		t.Fatal("anomali yang diabaikan masih terbuka")
	}
	// perangkat down: deret tidak dinilai, anomali port ditutup
	dbtest.Exec(t, db, `UPDATE devices SET status = 'down'`)
	_ = r.Run(ctx)
	if n := count(`SELECT count(*) FROM anomalies WHERE ended_at IS NULL`); n != 0 {
		t.Fatalf("perangkat down: %d anomali masih terbuka", n)
	}
}
