// Package forecast memperkirakan kapan sensor mencapai batasnya dari tren (regresi linear kuadrat terkecil):
// disk penuh (data 7 hari, per jam) dan bahan bakar genset habis (data sejak isi ulang terakhir, 48 jam terakhir).
// Hasil disimpan di sensor_forecasts dan menjadi problem "forecast" bila waktunya dekat.
package forecast

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"math"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

// Kind satu jenis prediksi.
type Kind struct {
	Class  string
	Window time.Duration
	Step   time.Duration
	Limit  float64 // batas yang dituju
	Rising bool    // true = naik menuju batas (disk), false = turun (bahan bakar)
	Refill bool    // potong data sebelum isi ulang terakhir
}

var Kinds = []Kind{
	{Class: "disk", Window: 7 * 24 * time.Hour, Step: time.Hour, Limit: 100, Rising: true},
	{Class: "fuel", Window: 48 * time.Hour, Step: 15 * time.Minute, Limit: 0, Rising: false, Refill: true},
}

// Minimal titik dan rentang data supaya tren tidak dari derau sesaat.
const (
	minPoints = 8
	minSpan   = 6 * time.Hour
)

type Point struct {
	T time.Time
	V float64
}

// Result prediksi satu seri.
type Result struct {
	Current, PerDay, R2 float64
	ETA                 *time.Time
	Points              int
}

// Fit menghitung tren. ok=false bila data kurang.
func Fit(pts []Point, k Kind, now time.Time) (Result, bool) {
	if k.Refill {
		pts = afterRefill(pts)
	}
	if len(pts) < minPoints || pts[len(pts)-1].T.Sub(pts[0].T) < minSpan {
		return Result{}, false
	}
	t0 := pts[0].T
	n := float64(len(pts))
	var mx, my float64
	for _, p := range pts {
		mx += p.T.Sub(t0).Hours() / 24
		my += p.V
	}
	mx, my = mx/n, my/n
	var sxx, sxy, syy float64
	for _, p := range pts {
		x := p.T.Sub(t0).Hours()/24 - mx
		y := p.V - my
		sxx, sxy, syy = sxx+x*x, sxy+x*y, syy+y*y
	}
	if sxx == 0 {
		return Result{}, false
	}
	b := sxy / sxx
	a := my - b*mx
	r := Result{Current: pts[len(pts)-1].V, PerDay: b, Points: len(pts)}
	if syy > 0 {
		r.R2 = sxy * sxy / (sxx * syy)
	}
	xNow := now.Sub(t0).Hours() / 24
	yNow := a + b*xNow
	toward := (k.Rising && b > 0) || (!k.Rising && b < 0)
	if toward {
		days := (k.Limit - yNow) / b
		if days < 0 {
			days = 0
		}
		if days < 3650 {
			t := now.Add(time.Duration(days * 24 * float64(time.Hour)))
			r.ETA = &t
		}
	}
	return r, true
}

// afterRefill: ambil data sesudah kenaikan besar terakhir (isi ulang tangki, > 5 poin persen atau > 5% nilai).
func afterRefill(pts []Point) []Point {
	start := 0
	for i := 1; i < len(pts); i++ {
		d := pts[i].V - pts[i-1].V
		if d > 5 && d > math.Abs(pts[i-1].V)*0.05 {
			start = i
		}
	}
	return pts[start:]
}

// Runner menghitung semua prediksi tiap jam.
type Runner struct {
	DB    *pgxpool.Pool
	VMURL string
	Log   *slog.Logger
	HTTP  *http.Client
}

type seriesKey struct {
	dev int64
	key string
}

func (r *Runner) Run(ctx context.Context) error {
	if r.VMURL == "" {
		return nil
	}
	now := time.Now()
	labels, err := r.labels(ctx)
	if err != nil {
		return err
	}
	seen := map[seriesKey]bool{}
	for _, k := range Kinds {
		series, err := r.rangeQuery(ctx, fmt.Sprintf(`max_over_time(antareja_sensor{class=%q}[%ds])`, k.Class, int(k.Step.Seconds())), now.Add(-k.Window), now, k.Step)
		if err != nil {
			return err
		}
		for sk, pts := range series {
			lbl, ok := labels[sk]
			if !ok {
				continue // sensor sudah tidak ada
			}
			res, ok := Fit(pts, k, now)
			if !ok {
				continue
			}
			seen[sk] = true
			var r2 any
			if !math.IsNaN(res.R2) {
				r2 = res.R2
			}
			if _, err := r.DB.Exec(ctx, `INSERT INTO sensor_forecasts (device_id, key, class, label, current, limit_value, per_day, r2, eta, points, computed_at)
				VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10, now())
				ON CONFLICT (device_id, key) DO UPDATE SET class = EXCLUDED.class, label = EXCLUDED.label, current = EXCLUDED.current,
					limit_value = EXCLUDED.limit_value, per_day = EXCLUDED.per_day, r2 = EXCLUDED.r2, eta = EXCLUDED.eta, points = EXCLUDED.points,
					computed_at = now()`, sk.dev, sk.key, k.Class, lbl, res.Current, k.Limit, res.PerDay, r2, res.ETA, res.Points); err != nil {
				return err
			}
		}
	}
	// prediksi yang datanya hilang atau sensornya dihapus dibuang
	_, err = r.DB.Exec(ctx, `DELETE FROM sensor_forecasts f WHERE computed_at < now() - interval '3 hours'
		OR NOT EXISTS (SELECT 1 FROM device_sensors s WHERE s.device_id = f.device_id AND s.key = f.key)`)
	if len(seen) > 0 {
		r.Log.Info("prediksi sensor dihitung", "seri", len(seen))
	}
	return err
}

func (r *Runner) labels(ctx context.Context) (map[seriesKey]string, error) {
	rows, err := r.DB.Query(ctx, `SELECT s.device_id, s.key, s.label FROM device_sensors s JOIN devices d ON d.id = s.device_id AND d.enabled
		WHERE s.class IN ('disk', 'fuel')`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[seriesKey]string{}
	for rows.Next() {
		var k seriesKey
		var l string
		if rows.Scan(&k.dev, &k.key, &l) == nil {
			out[k] = l
		}
	}
	return out, rows.Err()
}

func (r *Runner) rangeQuery(ctx context.Context, expr string, start, end time.Time, step time.Duration) (map[seriesKey][]Point, error) {
	cl := r.HTTP
	if cl == nil {
		cl = &http.Client{Timeout: 60 * time.Second}
	}
	u := strings.TrimRight(r.VMURL, "/") + "/api/v1/query_range?" + url.Values{"query": {expr},
		"start": {strconv.FormatInt(start.Unix(), 10)}, "end": {strconv.FormatInt(end.Unix(), 10)}, "step": {strconv.Itoa(int(step.Seconds()))}}.Encode()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return nil, err
	}
	resp, err := cl.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("VictoriaMetrics HTTP %d", resp.StatusCode)
	}
	var body struct {
		Data struct {
			Result []struct {
				Metric map[string]string `json:"metric"`
				Values [][2]any          `json:"values"`
			} `json:"result"`
		} `json:"data"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&body); err != nil {
		return nil, err
	}
	out := map[seriesKey][]Point{}
	for _, x := range body.Data.Result {
		d, err := strconv.ParseInt(x.Metric["device_id"], 10, 64)
		if err != nil || x.Metric["key"] == "" {
			continue
		}
		var pts []Point
		for _, v := range x.Values {
			ts, ok1 := v[0].(float64)
			s, ok2 := v[1].(string)
			f, err := strconv.ParseFloat(s, 64)
			if !ok1 || !ok2 || err != nil || math.IsNaN(f) {
				continue
			}
			pts = append(pts, Point{time.Unix(int64(ts), 0), f})
		}
		sort.Slice(pts, func(i, j int) bool { return pts[i].T.Before(pts[j].T) })
		out[seriesKey{d, x.Metric["key"]}] = pts
	}
	return out, nil
}
