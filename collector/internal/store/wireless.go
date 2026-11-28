package store

import (
	"context"
	"strings"
)

// WirelessRow satu link radio hasil baca SNMP (lihat snmp.WirelessLink).
type WirelessRow struct {
	Kind, MAC, Name, IP  string
	Signal, RemoteSignal *float64
	Noise, CCQ           *float64
	Quality, Cap         *float64
	DistanceM            *int
	TxMbps, RxMbps       *float64
	UptimeS              *int64
	Connected            bool
}

// wirelessKeep berapa lama link yang hilang tetap ditampilkan sebagai "terputus" sebelum dihapus.
const wirelessKeep = "7 days"

// SaveWireless menyimpan link radio perangkat ini. Link yang tidak muncul lagi ditandai terputus (bukan dihapus),
// supaya operator melihat station mana yang lepas. Link yang tersambung juga dicatat sebagai tetangga protokol
// 'wifi' lalu dicocokkan ke perangkat terdaftar (IP, nama, atau MAC interface) sehingga garis topologi terbentuk.
// Mengembalikan nama link yang baru terputus dan yang baru tersambung (untuk kejadian).
func (s Store) SaveWireless(ctx context.Context, deviceID int64, rows []WirelessRow) (lost, back []string, err error) {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return nil, nil, err
	}
	defer tx.Rollback(ctx) //nolint:errcheck

	prev := map[string]bool{}
	names := map[string]string{}
	rs, err := tx.Query(ctx, `SELECT mac, connected, COALESCE(name, ip, mac) FROM wireless_links WHERE device_id = $1`, deviceID)
	if err != nil {
		return nil, nil, err
	}
	for rs.Next() {
		var m, n string
		var c bool
		if err := rs.Scan(&m, &c, &n); err != nil {
			rs.Close()
			return nil, nil, err
		}
		prev[m], names[m] = c, n
	}
	rs.Close()

	seen := make([]string, 0, len(rows))
	for i := range rows {
		r := &rows[i]
		if r.MAC == "" {
			continue
		}
		// Tabel registrasi MikroTik tidak memuat IP lawan: cari dari tabel ARP router yang dipantau
		if r.IP == "" && !strings.HasPrefix(r.MAC, "ip:") {
			_ = tx.QueryRow(ctx, `SELECT host(ip) FROM arp_entries WHERE mac = lower($1) ORDER BY last_seen DESC LIMIT 1`, r.MAC).Scan(&r.IP)
		}
		seen = append(seen, r.MAC)
		label := r.Name
		if label == "" {
			label = r.IP
		}
		if label == "" {
			label = r.MAC
		}
		was, known := prev[r.MAC]
		switch {
		case r.Connected && known && !was:
			back = append(back, label)
		case !r.Connected && (!known || was):
			lost = append(lost, label)
		}
		if _, err := tx.Exec(ctx, `INSERT INTO wireless_links (device_id, mac, kind, name, ip, signal_dbm, remote_signal_dbm, noise_dbm, ccq_pct,
				quality_pct, capacity_pct, distance_m, tx_mbps, rx_mbps, uptime_s, connected, last_seen)
			VALUES ($1,$2,$3,NULLIF($4,''),NULLIF($5,''),$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,now())
			ON CONFLICT (device_id, mac) DO UPDATE SET kind = EXCLUDED.kind, name = COALESCE(EXCLUDED.name, wireless_links.name),
				ip = COALESCE(EXCLUDED.ip, wireless_links.ip), signal_dbm = EXCLUDED.signal_dbm, remote_signal_dbm = EXCLUDED.remote_signal_dbm,
				noise_dbm = EXCLUDED.noise_dbm, ccq_pct = EXCLUDED.ccq_pct, quality_pct = EXCLUDED.quality_pct, capacity_pct = EXCLUDED.capacity_pct,
				distance_m = EXCLUDED.distance_m, tx_mbps = EXCLUDED.tx_mbps, rx_mbps = EXCLUDED.rx_mbps, uptime_s = EXCLUDED.uptime_s,
				connected = EXCLUDED.connected, last_seen = CASE WHEN EXCLUDED.connected THEN now() ELSE wireless_links.last_seen END`,
			deviceID, r.MAC, r.Kind, cut(r.Name), r.IP, r.Signal, r.RemoteSignal, r.Noise, r.CCQ, r.Quality, r.Cap, r.DistanceM, r.TxMbps, r.RxMbps,
			r.UptimeS, r.Connected); err != nil {
			return nil, nil, err
		}
	}
	// Tidak muncul lagi di tabel station: terputus
	for m, was := range prev {
		if was && !contains(seen, m) {
			lost = append(lost, names[m])
		}
	}
	if _, err := tx.Exec(ctx, `UPDATE wireless_links SET connected = false WHERE device_id = $1 AND NOT (mac = ANY($2))`, deviceID, seen); err != nil {
		return nil, nil, err
	}
	if _, err := tx.Exec(ctx, `DELETE FROM wireless_links WHERE device_id = $1 AND NOT connected AND last_seen < now() - $2::interval`, deviceID, wirelessKeep); err != nil {
		return nil, nil, err
	}

	// Tetangga 'wifi' untuk topologi: hanya link yang tersambung
	if _, err := tx.Exec(ctx, `DELETE FROM neighbors WHERE device_id = $1 AND protocol = 'wifi'`, deviceID); err != nil {
		return nil, nil, err
	}
	platform := map[string]string{"airmax": "Ubiquiti airMAX", "airfiber": "Ubiquiti airFiber", "ltu": "Ubiquiti LTU", "mikrotik": "MikroTik wireless"}
	for _, r := range rows {
		if r.MAC == "" || !r.Connected {
			continue
		}
		chassis := r.MAC
		if strings.HasPrefix(chassis, "ip:") {
			chassis = ""
		}
		if _, err := tx.Exec(ctx, `INSERT INTO neighbors (device_id, local_port, protocol, remote_name, remote_chassis, remote_ip, remote_platform)
			VALUES ($1, 'radio', 'wifi', NULLIF($2,''), NULLIF($3,''), NULLIF($4,''), $5)`, deviceID, cut(r.Name), chassis, r.IP, platform[r.Kind]); err != nil {
			return nil, nil, err
		}
	}
	if err := matchNeighbors(ctx, tx, `n.device_id = $1 AND n.protocol = 'wifi'`, `n.device_id = $1 AND n.protocol = 'wifi'`, deviceID); err != nil {
		return nil, nil, err
	}
	if err := syncWirelessRemote(ctx, tx, `w.device_id = $1`, deviceID); err != nil {
		return nil, nil, err
	}
	return lost, back, tx.Commit(ctx)
}

// syncWirelessRemote menyalin hasil pencocokan tetangga 'wifi' ke wireless_links.remote_device_id.
func syncWirelessRemote(ctx context.Context, db execer, where string, args ...any) error {
	_, err := db.Exec(ctx, `UPDATE wireless_links w SET remote_device_id = (
			SELECT n.remote_device_id FROM neighbors n WHERE n.device_id = w.device_id AND n.protocol = 'wifi' AND n.remote_device_id IS NOT NULL
			  AND (n.remote_chassis = w.mac OR (n.remote_chassis IS NULL AND n.remote_ip = w.ip)) LIMIT 1)
		WHERE w.connected AND `+where, args...)
	return err
}

func contains(xs []string, s string) bool {
	for _, x := range xs {
		if x == s {
			return true
		}
	}
	return false
}
