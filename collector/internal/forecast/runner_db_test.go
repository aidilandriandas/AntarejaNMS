package forecast

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestRunnerDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('srv-disk', '10.5.5.5') RETURNING id`)
	if _, err := db.Exec(ctx, `INSERT INTO device_sensors (device_id, key, class, grp, label, value, status) VALUES ($1, 'px:disk:root', 'disk', 'sistem', 'Disk /', 90, 'warn')`, dev); err != nil {
		t.Fatal(err)
	}
	vm := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(r.URL.Query().Get("query"), `class="disk"`) {
			_, _ = io.WriteString(w, `{"data":{"result":[]}}`)
			return
		}
		now := time.Now().Unix()
		var vals [][2]any
		for h := 72; h >= 0; h-- {
			vals = append(vals, [2]any{float64(now - int64(h)*3600), fmt.Sprintf("%g", 90-float64(h)/24*2)})
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"result": []any{
			map[string]any{"metric": map[string]string{"device_id": fmt.Sprint(dev), "key": "px:disk:root"}, "values": vals},
			map[string]any{"metric": map[string]string{"device_id": "999999", "key": "hilang"}, "values": vals},
		}}})
	}))
	defer vm.Close()
	r := &Runner{DB: db, VMURL: vm.URL, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	var eta time.Time
	var n int
	if err := db.QueryRow(ctx, `SELECT eta, (SELECT count(*) FROM sensor_forecasts) FROM sensor_forecasts WHERE device_id = $1`, dev).Scan(&eta, &n); err != nil {
		t.Fatal(err)
	}
	if d := time.Until(eta).Hours() / 24; n != 1 || d < 4.8 || d > 5.2 {
		t.Fatalf("n=%d eta %.2f hari", n, d)
	}
}
