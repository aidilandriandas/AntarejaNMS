// Package incidents mengelompokkan problem menjadi insiden (satu gangguan nyata = satu insiden), dijalankan setiap
// putaran setelah engine problem.
//
// Aturan (dapat diatur di settings):
//   - Insiden dibuat otomatis bila ada problem Disaster, atau ≥ incident.group_min problem High/Disaster di satu site
//     yang muncul dalam incident.window_min menit (problem yang ditekan maintenance tidak dihitung).
//   - Problem High/Disaster baru, dan problem yang ditekan karena induknya down, digabung ke insiden yang masih aktif
//     (atau pulih ≤ 30 menit lalu — dibuka kembali) di site yang sama bila mulainya berdekatan (≤ incident.attach_min
//     menit) dengan problem di insiden itu. Problem yang berjauhan waktunya dikelompokkan terpisah.
//   - Insiden berstatus Pulih otomatis saat semua problemnya selesai. Insiden Ditutup tidak pernah diubah otomatis.
package incidents

import (
	"context"
	"fmt"
	"log/slog"
	"sort"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

type Runner struct {
	DB  *pgxpool.Pool
	Log *slog.Logger
}

type cfg struct {
	Auto      bool
	GroupMin  int
	Window    time.Duration
	AttachWin time.Duration
}

// Cand problem yang belum masuk insiden.
type Cand struct {
	ID         int64
	DeviceID   int64
	Site       int64 // 0 = tanpa site
	SiteName   string
	Device     string
	Severity   string
	Title      string
	Suppressed string // "", "maintenance", "parent"
	Parent     int64  // perangkat induk (0 = tidak ada)
	Started    time.Time
}

var rank = map[string]int{"info": 0, "warning": 1, "average": 2, "high": 3, "disaster": 4}

func (r *Runner) settings(ctx context.Context) cfg {
	c := cfg{Auto: true, GroupMin: 3, Window: 10 * time.Minute, AttachWin: 60 * time.Minute}
	rows, err := r.DB.Query(ctx, `SELECT key, value::text FROM settings WHERE key LIKE 'incident.%'`)
	if err != nil {
		return c
	}
	defer rows.Close()
	for rows.Next() {
		var k, v string
		if rows.Scan(&k, &v) != nil {
			continue
		}
		var n int
		_, _ = fmt.Sscan(strings.Trim(v, `"`), &n)
		switch k {
		case "incident.auto":
			c.Auto = v == "true"
		case "incident.group_min":
			if n >= 1 {
				c.GroupMin = n
			}
		case "incident.window_min":
			if n >= 1 {
				c.Window = time.Duration(n) * time.Minute
			}
		case "incident.attach_min":
			if n >= 1 {
				c.AttachWin = time.Duration(n) * time.Minute
			}
		}
	}
	return c
}

// Run satu putaran: gabung/buat insiden, lalu tandai insiden yang pulih.
func (r *Runner) Run(ctx context.Context) error {
	c := r.settings(ctx)
	if c.Auto {
		if err := r.group(ctx, c); err != nil {
			return err
		}
	}
	return r.recover(ctx)
}

func (r *Runner) group(ctx context.Context, c cfg) error {
	rows, err := r.DB.Query(ctx, `SELECT p.id, p.device_id, COALESCE(d.site_id, 0), COALESCE(s.code, ''), d.name, p.severity, p.title, COALESCE(p.suppressed, ''), p.started_at, COALESCE(d.parent_id, 0)
		FROM problems p JOIN devices d ON d.id = p.device_id LEFT JOIN sites s ON s.id = d.site_id
		WHERE p.resolved_at IS NULL AND (p.severity IN ('high', 'disaster') OR p.suppressed = 'parent')
		  AND NOT EXISTS (SELECT 1 FROM incident_problems ip WHERE ip.problem_id = p.id)
		ORDER BY p.started_at`)
	if err != nil {
		return err
	}
	bySite := map[int64][]Cand{}
	for rows.Next() {
		var x Cand
		if err := rows.Scan(&x.ID, &x.DeviceID, &x.Site, &x.SiteName, &x.Device, &x.Severity, &x.Title, &x.Suppressed, &x.Started, &x.Parent); err != nil {
			rows.Close()
			return err
		}
		bySite[x.Site] = append(bySite[x.Site], x)
	}
	rows.Close()

	// Anak yang ditekan karena induknya down masuk ke insiden yang memuat problem induknya, berapa pun jarak waktunya
	byParent := map[int64]int64{}
	if prow, err := r.DB.Query(ctx, `SELECT DISTINCT ON (p.device_id) p.device_id, i.id FROM incident_problems ip
		JOIN problems p ON p.id = ip.problem_id JOIN incidents i ON i.id = ip.incident_id
		WHERE p.resolved_at IS NULL AND i.status IN ('open', 'handling') ORDER BY p.device_id, i.opened_at DESC`); err == nil {
		for prow.Next() {
			var dev, inc int64
			if prow.Scan(&dev, &inc) == nil {
				byParent[dev] = inc
			}
		}
		prow.Close()
	}
	for site, list := range bySite {
		keep := list[:0]
		for _, x := range list {
			if inc, ok := byParent[x.Parent]; ok && x.Suppressed == "parent" {
				r.attach(ctx, inc, "open", []Cand{x})
				continue
			}
			keep = append(keep, x)
		}
		bySite[site] = keep
	}

	sites := make([]int64, 0, len(bySite))
	for s := range bySite {
		sites = append(sites, s)
	}
	sort.Slice(sites, func(i, j int) bool { return sites[i] < sites[j] })
	for _, site := range sites {
		incs, err := r.activeIncidents(ctx, site)
		if err != nil {
			return err
		}
		var rest []Cand
		for _, x := range bySite[site] {
			if k := match(incs, x, c.AttachWin); k >= 0 {
				r.attach(ctx, incs[k].ID, incs[k].Status, []Cand{x})
				incs[k].Status = "open" // sudah dibuka kembali bila tadinya pulih
				if x.Started.After(incs[k].Last) {
					incs[k].Last = x.Started
				}
				continue
			}
			rest = append(rest, x)
		}
		for _, cl := range cluster(rest, c.AttachWin) {
			if decide(cl, c) {
				r.create(ctx, site, cl)
			}
		}
	}
	return nil
}

// Inc insiden aktif di satu site: rentang waktu problemnya dipakai untuk menggabungkan problem baru.
type Inc struct {
	ID     int64
	Status string
	Opened time.Time
	Last   time.Time // kejadian terakhir: mulai/selesai problem terakhir atau waktu pulih
}

func (r *Runner) activeIncidents(ctx context.Context, site int64) ([]Inc, error) {
	rows, err := r.DB.Query(ctx, `SELECT i.id, i.status, i.opened_at, GREATEST(i.opened_at, max(p.started_at), max(p.resolved_at), i.recovered_at)
		FROM incidents i LEFT JOIN incident_problems ip ON ip.incident_id = i.id LEFT JOIN problems p ON p.id = ip.problem_id
		WHERE COALESCE(i.site_id, 0) = $1 AND (i.status IN ('open', 'handling') OR (i.status = 'recovered' AND i.recovered_at > now() - interval '30 minutes'))
		GROUP BY i.id ORDER BY i.opened_at DESC`, site)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Inc
	for rows.Next() {
		var x Inc
		if err := rows.Scan(&x.ID, &x.Status, &x.Opened, &x.Last); err != nil {
			return nil, err
		}
		out = append(out, x)
	}
	return out, rows.Err()
}

// match: insiden yang rentang waktunya (dibuka − w … kejadian terakhir + w) mencakup mulai problem x; -1 bila tidak ada.
func match(incs []Inc, x Cand, w time.Duration) int {
	for k, in := range incs {
		if !x.Started.Before(in.Opened.Add(-w)) && !x.Started.After(in.Last.Add(w)) {
			return k
		}
	}
	return -1
}

// cluster memecah problem (urut waktu mulai) menjadi kelompok yang jarak antar-problemnya ≤ gap,
// supaya problem lama yang tidak berkaitan tidak ikut masuk insiden baru.
func cluster(cands []Cand, gap time.Duration) [][]Cand {
	var out [][]Cand
	for i, x := range cands {
		if i == 0 || x.Started.Sub(cands[i-1].Started) > gap {
			out = append(out, nil)
		}
		out[len(out)-1] = append(out[len(out)-1], x)
	}
	return out
}

// decide: buat insiden bila kelompok berisi Disaster (tidak ditekan), atau ≥ GroupMin High/Disaster (tidak ditekan)
// yang mulai dalam rentang Window satu sama lain. cands urut waktu mulai.
func decide(cands []Cand, c cfg) bool {
	var hs []time.Time
	for _, x := range cands {
		if x.Suppressed != "" {
			continue
		}
		if x.Severity == "disaster" {
			return true
		}
		if rank[x.Severity] >= rank["high"] {
			hs = append(hs, x.Started)
		}
	}
	for i := 0; i+c.GroupMin-1 < len(hs); i++ {
		if hs[i+c.GroupMin-1].Sub(hs[i]) <= c.Window {
			return true
		}
	}
	return false
}

// Title judul insiden otomatis dari problem yang dikelompokkan.
func Title(cands []Cand) string {
	active := make([]Cand, 0, len(cands))
	devs := map[int64]bool{}
	for _, x := range cands {
		if x.Suppressed != "maintenance" {
			active = append(active, x)
			devs[x.DeviceID] = true
		}
	}
	if len(active) == 1 {
		return active[0].Title
	}
	where := "tanpa site"
	if active[0].SiteName != "" {
		where = "site " + active[0].SiteName
	}
	return fmt.Sprintf("Gangguan %s: %d perangkat terdampak", where, len(devs))
}

func maxSev(cands []Cand) string {
	s := "info"
	for _, x := range cands {
		if rank[x.Severity] > rank[s] {
			s = x.Severity
		}
	}
	return s
}

func (r *Runner) create(ctx context.Context, site int64, cands []Cand) {
	var siteArg any
	if site > 0 {
		siteArg = site
	}
	var id int64
	err := r.DB.QueryRow(ctx, `INSERT INTO incidents (title, severity, site_id, auto, opened_at) VALUES ($1, $2, $3, true, $4) RETURNING id`,
		trim(Title(cands), 255), maxSev(cands), siteArg, cands[0].Started).Scan(&id)
	if err != nil {
		r.Log.Warn("insiden gagal dibuat", "galat", err)
		return
	}
	_, _ = r.DB.Exec(ctx, `INSERT INTO incident_notes (incident_id, kind, body) VALUES ($1, 'system', $2)`, id,
		fmt.Sprintf("Insiden dibuat otomatis dari %d problem.", len(cands)))
	r.link(ctx, id, cands)
	r.Log.Info("insiden dibuat", "id", id, "problem", len(cands))
}

func (r *Runner) attach(ctx context.Context, id int64, status string, cands []Cand) {
	r.link(ctx, id, cands)
	_, _ = r.DB.Exec(ctx, `UPDATE incidents SET severity = CASE WHEN array_position(ARRAY['info','warning','average','high','disaster'], $2) >
		array_position(ARRAY['info','warning','average','high','disaster'], severity) THEN $2 ELSE severity END, updated_at = now() WHERE id = $1`, id, maxSev(cands))
	if status == "recovered" {
		_, _ = r.DB.Exec(ctx, `UPDATE incidents SET status = CASE WHEN owner_id IS NULL THEN 'open' ELSE 'handling' END, recovered_at = NULL, updated_at = now() WHERE id = $1`, id)
		_, _ = r.DB.Exec(ctx, `INSERT INTO incident_notes (incident_id, kind, body) VALUES ($1, 'status', 'Gangguan muncul lagi — insiden dibuka kembali.')`, id)
	}
}

func (r *Runner) link(ctx context.Context, id int64, cands []Cand) {
	for _, x := range cands {
		tag, err := r.DB.Exec(ctx, `INSERT INTO incident_problems (incident_id, problem_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, id, x.ID)
		if err == nil && tag.RowsAffected() > 0 {
			note := x.Title
			if x.Suppressed == "parent" {
				note += " (terdampak perangkat induk)"
			}
			_, _ = r.DB.Exec(ctx, `INSERT INTO incident_notes (incident_id, kind, body) VALUES ($1, 'problem', $2)`, id, "Problem masuk: "+trim(note, 400))
		}
	}
}

// recover menandai insiden aktif yang semua problemnya sudah selesai.
func (r *Runner) recover(ctx context.Context) error {
	rows, err := r.DB.Query(ctx, `UPDATE incidents i SET status = 'recovered', recovered_at = now(), updated_at = now()
		WHERE i.status IN ('open', 'handling')
		  AND EXISTS (SELECT 1 FROM incident_problems ip WHERE ip.incident_id = i.id)
		  AND NOT EXISTS (SELECT 1 FROM incident_problems ip JOIN problems p ON p.id = ip.problem_id WHERE ip.incident_id = i.id AND p.resolved_at IS NULL)
		RETURNING i.id`)
	if err != nil {
		return err
	}
	var ids []int64
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			ids = append(ids, id)
		}
	}
	rows.Close()
	for _, id := range ids {
		_, _ = r.DB.Exec(ctx, `INSERT INTO incident_notes (incident_id, kind, body) VALUES ($1, 'status', 'Semua problem sudah pulih — status Pulih. Isi penyebab & tindakan lalu tutup insiden.')`, id)
		r.Log.Info("insiden pulih", "id", id)
	}
	return nil
}

func trim(s string, n int) string {
	if len(s) > n {
		return strings.ToValidUTF8(s[:n], "") + "…"
	}
	return s
}
