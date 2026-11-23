package anomaly

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"math"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

const (
	Days      = 14 // riwayat jam yang sama
	needHits  = 2  // putaran menyimpang berturut-turut sebelum dicatat
	needCalms = 2  // putaran normal berturut-turut sebelum ditutup
)

var exprs = map[string]string{
	Latency: `avg_over_time(antareja_ping_rtt_ms[15m])`,
	PortIn:  `rate(antareja_if_in_octets_total[15m]) * 8`,
	PortOut: `rate(antareja_if_out_octets_total[15m]) * 8`,
}

// Runner menjalankan penilaian berkala. Satu instance, dipanggil dari satu goroutine.
type Runner struct {
	DB    *pgxpool.Pool
	VMURL string
	Log   *slog.Logger
	HTTP  *http.Client
	track map[string]*streak
}

type streak struct{ hits, calms int }

type series struct {
	dev  int64
	idx  int // -1 untuk latensi
	name string
}

func (s series) key(metric string) string { return fmt.Sprintf("%d/%s/%d", s.dev, metric, s.idx) }

type openRow struct {
	id    int64
	score float64
}

// Run satu putaran penilaian.
func (r *Runner) Run(ctx context.Context) error {
	if r.VMURL == "" {
		return nil
	}
	var enabled bool
	if err := r.DB.QueryRow(ctx, `SELECT COALESCE((SELECT value::text::boolean FROM settings WHERE key = 'anomaly.enabled'), true)`).Scan(&enabled); err != nil {
		return err
	}
	if r.track == nil {
		r.track = map[string]*streak{}
	}
	if !enabled {
		_, err := r.DB.Exec(ctx, `UPDATE anomalies SET ended_at = now() WHERE ended_at IS NULL`)
		r.track = map[string]*streak{}
		return err
	}
	devs, ports, err := r.eligible(ctx)
	if err != nil {
		return err
	}
	muted, err := r.mutes(ctx)
	if err != nil {
		return err
	}
	open, err := r.open(ctx)
	if err != nil {
		return err
	}
	now := time.Now()
	seen := map[string]bool{}
	for metric, expr := range exprs {
		cur, err := r.query(ctx, expr, now)
		if err != nil {
			return fmt.Errorf("%s: %w", metric, err)
		}
		hist := map[string][]float64{}
		for d := 1; d <= Days; d++ {
			res, err := r.query(ctx, expr, now.Add(-time.Duration(d)*24*time.Hour))
			if err != nil {
				return fmt.Errorf("%s riwayat: %w", metric, err)
			}
			for k, v := range res {
				hist[k.key(metric)] = append(hist[k.key(metric)], v.val)
			}
		}
		for s, c := range cur {
			k := s.key(metric)
			if !devs[s.dev] || (metric != Latency && !ports[[2]int64{s.dev, int64(s.idx)}]) {
				continue // perangkat down atau port mati: sudah ada problem sendiri
			}
			seen[k] = true
			v, ok := Judge(metric, c.val, hist[k])
			st := r.track[k]
			if st == nil {
				st = &streak{}
				r.track[k] = st
			}
			o, isOpen := open[k]
			if ok && v.Anomalous && !muted[k] {
				st.hits++
				st.calms = 0
				if isOpen {
					_, err = r.DB.Exec(ctx, `UPDATE anomalies SET value = $2, baseline = $3, spread = $4, direction = $5, updated_at = now(),
						score = CASE WHEN abs($6) > abs(score) THEN $6 ELSE score END WHERE id = $1`, o.id, c.val, v.Baseline, v.Spread, v.Direction, v.Score)
				} else if st.hits >= needHits {
					err = r.create(ctx, metric, s, c.val, v)
				}
				if err != nil {
					return err
				}
				continue
			}
			st.hits = 0
			if !isOpen {
				continue
			}
			if muted[k] || !ok || v.Normal {
				st.calms++
			} else {
				st.calms = 0 // masih di antara: belum menyimpang jauh, belum juga wajar
			}
			if muted[k] || st.calms >= needCalms {
				if _, err := r.DB.Exec(ctx, `UPDATE anomalies SET ended_at = now(), value = $2 WHERE id = $1`, o.id, c.val); err != nil {
					return err
				}
				delete(r.track, k)
				continue
			}
			if ok {
				if _, err := r.DB.Exec(ctx, `UPDATE anomalies SET value = $2, updated_at = now() WHERE id = $1`, o.id, c.val); err != nil {
					return err
				}
			}
		}
	}
	// Deret yang hilang (perangkat down, port mati, perangkat dihapus dari pantauan): anomali ditutup
	for k, o := range open {
		if !seen[k] {
			if _, err := r.DB.Exec(ctx, `UPDATE anomalies SET ended_at = now() WHERE id = $1`, o.id); err != nil {
				return err
			}
		}
	}
	for k := range r.track {
		if !seen[k] {
			delete(r.track, k)
		}
	}
	return nil
}

func (r *Runner) create(ctx context.Context, metric string, s series, cur float64, v Verdict) error {
	var idx *int
	var name *string
	if metric != Latency {
		i := s.idx
		idx, name = &i, &s.name
	}
	tag, err := r.DB.Exec(ctx, `INSERT INTO anomalies (device_id, metric, if_index, if_name, direction, value, baseline, spread, score)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9) ON CONFLICT DO NOTHING`, s.dev, metric, idx, name, v.Direction, cur, v.Baseline, v.Spread, v.Score)
	if err != nil || tag.RowsAffected() == 0 {
		return err
	}
	msg := Describe(metric, s.name, v.Direction, cur, v.Baseline)
	_, err = r.DB.Exec(ctx, `INSERT INTO events (device_id, kind, message) VALUES ($1, 'anomaly', $2)`, s.dev, msg)
	r.Log.Info("anomali", "perangkat", s.dev, "pesan", msg)
	return err
}

// Describe kalimat singkat untuk kejadian dan judul problem.
func Describe(metric, port, dir string, cur, base float64) string {
	verb := map[string]string{"up": "naik tidak biasa", "down": "turun tidak biasa"}[dir]
	switch metric {
	case Latency:
		return fmt.Sprintf("Latensi %s: %s ms, biasanya %s ms di jam ini", verb, Num(cur), Num(base))
	case PortIn, PortOut:
		what := "masuk"
		if metric == PortOut {
			what = "keluar"
		}
		if dir == "up" {
			verb = "melonjak"
		} else {
			verb = "anjlok"
		}
		return strings.TrimSpace(fmt.Sprintf("Trafik %s %s %s: %s, biasanya %s di jam ini", what, port, verb, Bps(cur), Bps(base)))
	}
	return metric
}

// Num: angka gaya Indonesia, satu desimal bila perlu.
func Num(v float64) string {
	s := strconv.FormatFloat(v, 'f', 1, 64)
	if v >= 100 {
		s = strconv.FormatFloat(v, 'f', 0, 64)
	}
	s = strings.TrimSuffix(s, ".0")
	return strings.Replace(s, ".", ",", 1)
}

// Bps: bit/detik dengan satuan yang pas.
func Bps(v float64) string {
	switch {
	case v >= 1e9:
		return Num(v/1e9) + " Gbps"
	case v >= 1e6:
		return Num(v/1e6) + " Mbps"
	case v >= 1e3:
		return Num(v/1e3) + " kbps"
	}
	return Num(v) + " bps"
}

func (r *Runner) eligible(ctx context.Context) (map[int64]bool, map[[2]int64]bool, error) {
	devs := map[int64]bool{}
	rows, err := r.DB.Query(ctx, `SELECT id FROM devices WHERE enabled AND status = 'up'`)
	if err != nil {
		return nil, nil, err
	}
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			devs[id] = true
		}
	}
	rows.Close()
	ports := map[[2]int64]bool{}
	rows, err = r.DB.Query(ctx, `SELECT device_id, if_index FROM interfaces WHERE oper_up AND COALESCE(admin_up, true)`)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var d, i int64
		if rows.Scan(&d, &i) == nil {
			ports[[2]int64{d, i}] = true
		}
	}
	return devs, ports, rows.Err()
}

func (r *Runner) mutes(ctx context.Context) (map[string]bool, error) {
	out := map[string]bool{}
	rows, err := r.DB.Query(ctx, `SELECT device_id, metric, if_index FROM anomaly_mutes WHERE until > now()`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var d int64
		var m string
		var i int
		if rows.Scan(&d, &m, &i) == nil {
			out[series{dev: d, idx: i}.key(m)] = true
		}
	}
	return out, rows.Err()
}

func (r *Runner) open(ctx context.Context) (map[string]openRow, error) {
	out := map[string]openRow{}
	rows, err := r.DB.Query(ctx, `SELECT id, device_id, metric, COALESCE(if_index, -1), score FROM anomalies WHERE ended_at IS NULL`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var o openRow
		var d int64
		var m string
		var i int
		if rows.Scan(&o.id, &d, &m, &i, &o.score) == nil {
			out[series{dev: d, idx: i}.key(m)] = o
		}
	}
	return out, rows.Err()
}

type sample struct{ val float64 }

// query: kueri instan PromQL ke VictoriaMetrics pada waktu t.
func (r *Runner) query(ctx context.Context, expr string, t time.Time) (map[series]sample, error) {
	cl := r.HTTP
	if cl == nil {
		cl = &http.Client{Timeout: 30 * time.Second}
	}
	u := strings.TrimRight(r.VMURL, "/") + "/api/v1/query?" + url.Values{"query": {expr}, "time": {strconv.FormatInt(t.Unix(), 10)}}.Encode()
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
	return parse(json.NewDecoder(resp.Body))
}

func parse(dec *json.Decoder) (map[series]sample, error) {
	var body struct {
		Data struct {
			Result []struct {
				Metric map[string]string `json:"metric"`
				Value  [2]any            `json:"value"`
			} `json:"result"`
		} `json:"data"`
	}
	if err := dec.Decode(&body); err != nil {
		return nil, err
	}
	out := map[series]sample{}
	for _, x := range body.Data.Result {
		d, err := strconv.ParseInt(x.Metric["device_id"], 10, 64)
		if err != nil {
			continue
		}
		idx := -1
		if s, ok := x.Metric["if_index"]; ok {
			if idx, err = strconv.Atoi(s); err != nil {
				continue
			}
		}
		vs, _ := x.Value[1].(string)
		v, err := strconv.ParseFloat(vs, 64)
		if err != nil || math.IsNaN(v) {
			continue
		}
		out[series{dev: d, idx: idx, name: x.Metric["if_name"]}] = sample{val: v}
	}
	return out, nil
}
