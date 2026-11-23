package notify

import (
	"context"
	"fmt"
	"strings"

	"github.com/jackc/pgx/v5/pgxpool"
)

// ImpactLine ringkasan dampak bila perangkat ini mati, sama dengan halaman Dampak di web: perangkat di bawahnya
// (relasi induk), kamera terkait, layanan yang jatuh di bawah syarat minimal hidup, dan site yang putus total.
// Kosong bila tidak berdampak atau data tidak bisa dibaca (notifikasi tetap dikirim tanpa baris dampak).
func ImpactLine(ctx context.Context, db *pgxpool.Pool, deviceID int64) string {
	rows, err := db.Query(ctx, `WITH RECURSIVE t(id, depth) AS (
			SELECT $1::bigint, 0
			UNION SELECT d.id, t.depth + 1 FROM devices d JOIN t ON d.parent_id = t.id WHERE d.enabled AND t.depth < 32)
		SELECT DISTINCT id FROM t`, deviceID)
	if err != nil {
		return ""
	}
	out := map[int64]bool{}
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			out[id] = true
		}
	}
	rows.Close()
	ids := make([]int64, 0, len(out))
	for id := range out {
		ids = append(ids, id)
	}
	var parts []string
	if n := len(ids) - 1; n > 0 {
		parts = append(parts, fmt.Sprintf("%d perangkat", n))
	}
	var cams int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM cameras WHERE device_id = ANY($1)`, ids).Scan(&cams)
	if cams > 0 {
		parts = append(parts, fmt.Sprintf("%d kamera", cams))
	}

	// Layanan: per komponen, anggota hidup = tidak down dan tidak ikut terdampak
	type comp struct {
		minUp, n, aliveBefore, aliveAfter int
		touched                           bool
	}
	crows, err := db.Query(ctx, `SELECT c.service_id, c.id, c.min_up, d.id, d.status FROM service_components c
		JOIN service_component_devices cd ON cd.component_id = c.id JOIN devices d ON d.id = cd.device_id`)
	if err == nil {
		comps := map[int64]map[int64]*comp{}
		for crows.Next() {
			var sid, cid, did int64
			var minUp int
			var st string
			if crows.Scan(&sid, &cid, &minUp, &did, &st) != nil {
				continue
			}
			if comps[sid] == nil {
				comps[sid] = map[int64]*comp{}
			}
			c := comps[sid][cid]
			if c == nil {
				c = &comp{minUp: minUp}
				comps[sid][cid] = c
			}
			c.n++
			if st != "down" {
				c.aliveBefore++
				if !out[did] {
					c.aliveAfter++
				}
			}
			c.touched = c.touched || out[did]
		}
		crows.Close()
		state := func(cs map[int64]*comp, after bool) string {
			s := "up"
			for _, c := range cs {
				alive := c.aliveBefore
				if after {
					alive = c.aliveAfter
				}
				switch {
				case alive < c.minUp:
					return "down"
				case alive < c.n:
					s = "degraded"
				}
			}
			return s
		}
		down, degraded := 0, 0
		for _, cs := range comps {
			touched := false
			for _, c := range cs {
				touched = touched || c.touched
			}
			before, after := state(cs, false), state(cs, true)
			if !touched || before == after {
				continue
			}
			if after == "down" {
				down++
			} else if after == "degraded" {
				degraded++
			}
		}
		if down > 0 {
			parts = append(parts, fmt.Sprintf("%d layanan terganggu", down))
		}
		if degraded > 0 {
			parts = append(parts, fmt.Sprintf("%d layanan tanpa cadangan", degraded))
		}
	}

	var sites int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM (SELECT site_id FROM devices WHERE enabled AND site_id IS NOT NULL
		GROUP BY site_id HAVING bool_and(id = ANY($1))) s`, ids).Scan(&sites)
	if sites > 0 {
		parts = append(parts, fmt.Sprintf("%d site putus total", sites))
	}
	return strings.Join(parts, ", ")
}
