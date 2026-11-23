package report

import (
	"context"
	"sort"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

// SvcRow ketersediaan satu layanan dalam periode (rumus sama dengan halaman SLA layanan di web).
type SvcRow struct {
	Name     string
	Target   float64 // target layanan, atau target global
	Avail    float64 // persen; -1 = belum dipantau dalam periode
	Down     float64 // detik
	Outages  int
	Longest  float64
	Degraded float64 // detik tanpa cadangan (ada anggota down, layanan masih jalan)
}

type iv struct{ a, b float64 }

func union(in []iv) []iv {
	s := append([]iv(nil), in...)
	sort.Slice(s, func(i, j int) bool { return s[i].a < s[j].a })
	var out []iv
	for _, x := range s {
		if n := len(out); n > 0 && x.a <= out[n-1].b {
			if x.b > out[n-1].b {
				out[n-1].b = x.b
			}
		} else if x.b > x.a {
			out = append(out, x)
		}
	}
	return out
}

// subtract a dikurangi b; keduanya sudah digabung dan urut.
func subtract(a, b []iv) []iv {
	var out []iv
	for _, r := range a {
		x, y := r.a, r.b
		for _, m := range b {
			if m.b <= x || m.a >= y {
				continue
			}
			if m.a > x {
				out = append(out, iv{x, m.a})
			}
			if m.b > x {
				x = m.b
			}
			if x >= y {
				break
			}
		}
		if x < y {
			out = append(out, iv{x, y})
		}
	}
	return out
}

func length(in []iv) float64 {
	t := 0.0
	for _, x := range in {
		t += x.b - x.a
	}
	return t
}

// belowMin: interval saat anggota yang hidup kurang dari min. ints = interval down per anggota.
func belowMin(ints [][]iv, n, min int) []iv {
	need := n - min + 1
	if n == 0 || need <= 0 {
		return nil
	}
	type ev struct {
		t  float64
		dx int
	}
	var evs []ev
	for _, l := range ints {
		for _, x := range l {
			evs = append(evs, ev{x.a, 1}, ev{x.b, -1})
		}
	}
	sort.Slice(evs, func(i, j int) bool {
		if evs[i].t != evs[j].t {
			return evs[i].t < evs[j].t
		}
		return evs[i].dx < evs[j].dx // akhir sebelum awal di detik yang sama
	})
	var out []iv
	cnt := 0
	from, open := 0.0, false
	for _, e := range evs {
		cnt += e.dx
		if cnt >= need && !open {
			from, open = e.t, true
		} else if cnt < need && open {
			if e.t > from {
				out = append(out, iv{from, e.t})
			}
			open = false
		}
	}
	return union(out)
}

type svcComp struct {
	minUp int
	devs  []int64
}

type svcDef struct {
	name   string
	target float64
	first  float64 // epoch perangkat pertama ditambahkan
	comps  []svcComp
}

// computeServices menghitung SvcRow dari definisi dan interval down per perangkat (sudah dikurangi maintenance).
func computeServices(defs []svcDef, down map[int64][]iv, s, e float64) []SvcRow {
	out := make([]SvcRow, 0, len(defs))
	for _, d := range defs {
		var svcDown, member []iv
		for _, c := range d.comps {
			ints := make([][]iv, 0, len(c.devs))
			for _, id := range c.devs {
				ints = append(ints, down[id])
				member = append(member, down[id]...)
			}
			svcDown = append(svcDown, belowMin(ints, len(c.devs), c.minUp)...)
		}
		svcDown = union(svcDown)
		start := s
		if d.first > start {
			start = d.first
		}
		mon := e - start
		r := SvcRow{Name: d.name, Target: d.target, Avail: -1, Down: length(svcDown), Outages: len(svcDown), Degraded: length(subtract(union(member), svcDown))}
		for _, x := range svcDown {
			if x.b-x.a > r.Longest {
				r.Longest = x.b - x.a
			}
		}
		if mon > 0 {
			r.Avail = (1 - r.Down/mon) * 100
		}
		out = append(out, r)
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Avail < out[j].Avail })
	return out
}

// buildServices: layanan site ini (atau semua bila siteID 0), urut dari ketersediaan terendah.
func buildServices(ctx context.Context, db *pgxpool.Pool, p Period, siteID int64, d *Data) {
	end := p.End
	if now := time.Now(); end.After(now) {
		end = now
	}
	if !end.After(p.Start) {
		return
	}
	rows, err := db.Query(ctx, `SELECT s.id, s.name, COALESCE(s.target_pct::float8, $2), c.id, c.min_up, cd.device_id, EXTRACT(EPOCH FROM dv.created_at)
		FROM services s JOIN service_components c ON c.service_id = s.id
		JOIN service_component_devices cd ON cd.component_id = c.id JOIN devices dv ON dv.id = cd.device_id
		WHERE ($1::bigint = 0 OR s.site_id = $1) ORDER BY s.name, c.position, c.id`, siteID, d.Target)
	if err != nil {
		return
	}
	var defs []svcDef
	idx := map[int64]int{}
	cidx := map[int64]int{}
	devSet := map[int64]bool{}
	for rows.Next() {
		var sid, cid, dev int64
		var name string
		var target, created float64
		var minUp int
		if rows.Scan(&sid, &name, &target, &cid, &minUp, &dev, &created) != nil {
			continue
		}
		i, ok := idx[sid]
		if !ok {
			i = len(defs)
			idx[sid] = i
			defs = append(defs, svcDef{name: name, target: target, first: created})
		}
		if created < defs[i].first {
			defs[i].first = created
		}
		j, ok := cidx[cid]
		if !ok {
			j = len(defs[i].comps)
			cidx[cid] = j
			defs[i].comps = append(defs[i].comps, svcComp{minUp: minUp})
		}
		defs[i].comps[j].devs = append(defs[i].comps[j].devs, dev)
		devSet[dev] = true
	}
	rows.Close()
	if len(defs) == 0 {
		return
	}
	ids := make([]int64, 0, len(devSet))
	for id := range devSet {
		ids = append(ids, id)
	}
	down := map[int64][]iv{}
	maint := map[int64][]iv{}
	load := func(q string, dst map[int64][]iv) error {
		r, err := db.Query(ctx, q, ids, p.Start, end)
		if err != nil {
			return err
		}
		defer r.Close()
		for r.Next() {
			var id int64
			var a, b float64
			if r.Scan(&id, &a, &b) == nil && b > a {
				dst[id] = append(dst[id], iv{a, b})
			}
		}
		return r.Err()
	}
	if load(`SELECT device_id, EXTRACT(EPOCH FROM GREATEST(started_at, $2)), EXTRACT(EPOCH FROM LEAST(COALESCE(resolved_at, now()), $3))
		FROM problems WHERE kind = 'down' AND device_id = ANY($1) AND started_at < $3 AND COALESCE(resolved_at, now()) > $2`, down) != nil {
		return
	}
	if load(`SELECT dv.id, EXTRACT(EPOCH FROM GREATEST(m.starts_at, $2)), EXTRACT(EPOCH FROM LEAST(m.ends_at, $3))
		FROM maintenances m JOIN devices dv ON dv.id = ANY($1)
		WHERE m.starts_at < $3 AND m.ends_at > $2
		  AND (m.scope = 'all' OR (m.scope = 'site' AND m.site_id = dv.site_id)
		       OR (m.scope = 'devices' AND EXISTS (SELECT 1 FROM maintenance_devices md WHERE md.maintenance_id = m.id AND md.device_id = dv.id)))`, maint) != nil {
		return
	}
	for id, l := range down {
		down[id] = subtract(union(l), union(maint[id]))
	}
	d.Services = computeServices(defs, down, float64(p.Start.Unix()), float64(end.Unix()))
}
