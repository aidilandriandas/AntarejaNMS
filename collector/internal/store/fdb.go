package store

import (
	"context"
	"time"

	"github.com/jackc/pgx/v5"
)

// FDBRow satu MAC di tabel switch.
type FDBRow struct {
	IfIndex int
	VLAN    int
	MAC     string
}

// fdbFresh: entri yang tidak terlihat lebih lama dari ini tidak dipakai menentukan lokasi (MAC sudah lepas).
const fdbFresh = 20 * time.Minute

// SaveFDB menyimpan tabel MAC satu switch (entri yang hilang dibiarkan menua, dihapus PruneFDB).
func (s Store) SaveFDB(ctx context.Context, deviceID int64, rows []FDBRow) error {
	if len(rows) == 0 {
		return nil
	}
	b := &pgx.Batch{}
	for _, r := range rows {
		b.Queue(`INSERT INTO fdb_entries (device_id, mac, vlan, if_index) VALUES ($1, $2, $3, $4)
			ON CONFLICT (device_id, mac, vlan) DO UPDATE SET
				first_seen = CASE WHEN fdb_entries.if_index <> EXCLUDED.if_index THEN now() ELSE fdb_entries.first_seen END,
				if_index = EXCLUDED.if_index, last_seen = now()`, deviceID, r.MAC, r.VLAN, r.IfIndex)
	}
	return s.DB.SendBatch(ctx, b).Close()
}

// MACLocation hasil penentuan port tepi satu MAC.
type MACLocation struct {
	MAC      string
	DeviceID int64
	IfIndex  int
	VLAN     int
}

type fdbCand struct {
	MACLocation
	portMACs int
	seen     time.Time
}

// PickEdge memilih lokasi tiap MAC dari pengamatan semua switch: port tanpa tetangga/sambungan (bukan uplink),
// dengan MAC paling sedikit; seri → yang terakhir terlihat, lalu id perangkat terkecil.
// Port dengan lebih dari maxPortMACs MAC dianggap trunk/uplink tanpa LLDP dan tidak dipakai.
func PickEdge(cands []fdbCand, maxPortMACs int) map[string]MACLocation {
	best := map[string]fdbCand{}
	for _, c := range cands {
		if c.portMACs > maxPortMACs {
			continue
		}
		b, ok := best[c.MAC]
		if !ok || c.portMACs < b.portMACs || (c.portMACs == b.portMACs && (c.seen.After(b.seen) || (c.seen.Equal(b.seen) && c.DeviceID < b.DeviceID))) {
			best[c.MAC] = c
		}
	}
	out := make(map[string]MACLocation, len(best))
	for m, c := range best {
		out[m] = c.MACLocation
	}
	return out
}

// RecomputeMACLocations memperbarui lokasi tepi semua MAC dan mencatat pindah port. Mengembalikan jumlah pindah.
func (s Store) RecomputeMACLocations(ctx context.Context) (int, error) {
	rows, err := s.DB.Query(ctx, `WITH fresh AS (
			SELECT f.* FROM fdb_entries f JOIN devices d ON d.id = f.device_id AND d.enabled
			WHERE f.last_seen > now() - make_interval(secs => $1)),
		portcount AS (SELECT device_id, if_index, count(DISTINCT mac) n FROM fresh GROUP BY device_id, if_index),
		infra AS (
			SELECT device_id, local_if_index AS if_index FROM neighbors WHERE local_if_index IS NOT NULL
			UNION SELECT a_device_id, a_if_index FROM device_links WHERE a_if_index IS NOT NULL
			UNION SELECT b_device_id, b_if_index FROM device_links WHERE b_if_index IS NOT NULL)
		SELECT f.mac, f.device_id, f.if_index, f.vlan, pc.n, f.last_seen
		FROM fresh f JOIN portcount pc USING (device_id, if_index)
		WHERE NOT EXISTS (SELECT 1 FROM infra i WHERE i.device_id = f.device_id AND i.if_index = f.if_index)`, fdbFresh.Seconds())
	if err != nil {
		return 0, err
	}
	var cands []fdbCand
	for rows.Next() {
		var c fdbCand
		if err := rows.Scan(&c.MAC, &c.DeviceID, &c.IfIndex, &c.VLAN, &c.portMACs, &c.seen); err != nil {
			rows.Close()
			return 0, err
		}
		cands = append(cands, c)
	}
	rows.Close()
	loc := PickEdge(cands, 12)

	prev := map[string]MACLocation{}
	pr, err := s.DB.Query(ctx, `SELECT mac, device_id, if_index, vlan FROM mac_locations`)
	if err != nil {
		return 0, err
	}
	for pr.Next() {
		var l MACLocation
		if err := pr.Scan(&l.MAC, &l.DeviceID, &l.IfIndex, &l.VLAN); err != nil {
			pr.Close()
			return 0, err
		}
		prev[l.MAC] = l
	}
	pr.Close()

	b := &pgx.Batch{}
	moves := 0
	for mac, l := range loc {
		old, ok := prev[mac]
		switch {
		case !ok:
			b.Queue(`INSERT INTO mac_locations (mac, device_id, if_index, vlan) VALUES ($1, $2, $3, $4) ON CONFLICT (mac) DO NOTHING`, mac, l.DeviceID, l.IfIndex, l.VLAN)
		case old.DeviceID != l.DeviceID || old.IfIndex != l.IfIndex:
			b.Queue(`INSERT INTO mac_moves (mac, from_device_id, from_if_index, to_device_id, to_if_index) VALUES ($1, $2, $3, $4, $5)`,
				mac, old.DeviceID, old.IfIndex, l.DeviceID, l.IfIndex)
			b.Queue(`UPDATE mac_locations SET device_id = $2, if_index = $3, vlan = $4, first_seen = now(), last_seen = now() WHERE mac = $1`, mac, l.DeviceID, l.IfIndex, l.VLAN)
			moves++
		default:
			b.Queue(`UPDATE mac_locations SET vlan = $2, last_seen = now() WHERE mac = $1`, mac, l.VLAN)
		}
	}
	if err := s.DB.SendBatch(ctx, b).Close(); err != nil {
		return moves, err
	}
	return moves, nil
}

// PruneFDB menghapus entri MAC yang tidak terlihat 7 hari, lokasi yang tidak terlihat 90 hari, riwayat > 90 hari.
func (s Store) PruneFDB(ctx context.Context) error {
	for _, q := range []string{
		`DELETE FROM fdb_entries WHERE last_seen < now() - interval '7 days'`,
		`DELETE FROM mac_locations WHERE last_seen < now() - interval '90 days'`,
		`DELETE FROM mac_moves WHERE at < now() - interval '90 days'`,
	} {
		if _, err := s.DB.Exec(ctx, q); err != nil {
			return err
		}
	}
	return nil
}
