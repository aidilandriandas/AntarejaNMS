// Package store membaca dan menulis data perangkat di PostgreSQL.
package store

import (
	"context"
	"encoding/json"
	"github.com/jackc/pgx/v5/pgconn"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

type Device struct {
	ID          int64
	Name, Host  string
	PingEnabled bool
	SNMPEnabled bool
	SNMPVersion string
	SNMPPort    int
	// Kredensial masih terenkripsi; dibuka oleh poller tepat sebelum dipakai.
	CommunityEnc, V3User, V3Level, V3AuthProto, V3AuthEnc, V3PrivProto, V3PrivEnc *string

	Status          string
	StatusChangedAt *time.Time
	FailStreak      int
	LastRTT         *float32
	SNMPOK          *bool
	LastSNMPAt      *time.Time
	UptimeSeconds   *int64
	SysObjectID     *string
	Category        string
	ThresholdsJSON  []byte
	CollectorID     *int64 // collector site yang memantau; nil = collector pusat
}

type Store struct{ DB *pgxpool.Pool }

const deviceCols = `id, name, host, ping_enabled, snmp_enabled, snmp_version, snmp_port,
	snmp_community_enc, snmp_v3_user, snmp_v3_level, snmp_v3_auth_proto, snmp_v3_auth_enc, snmp_v3_priv_proto, snmp_v3_priv_enc,
	status, status_changed_at, fail_streak, last_rtt_ms, snmp_ok, last_snmp_at, uptime_seconds, sys_object_id, category, thresholds, collector_id`

func scanDevice(r pgx.Row) (Device, error) {
	var d Device
	err := r.Scan(&d.ID, &d.Name, &d.Host, &d.PingEnabled, &d.SNMPEnabled, &d.SNMPVersion, &d.SNMPPort,
		&d.CommunityEnc, &d.V3User, &d.V3Level, &d.V3AuthProto, &d.V3AuthEnc, &d.V3PrivProto, &d.V3PrivEnc,
		&d.Status, &d.StatusChangedAt, &d.FailStreak, &d.LastRTT, &d.SNMPOK, &d.LastSNMPAt, &d.UptimeSeconds, &d.SysObjectID, &d.Category, &d.ThresholdsJSON, &d.CollectorID)
	return d, err
}

// Devices mengembalikan semua perangkat aktif.
func (s Store) Devices(ctx context.Context) ([]Device, error) {
	rows, err := s.DB.Query(ctx, `SELECT `+deviceCols+` FROM devices WHERE enabled ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Device
	for rows.Next() {
		d, err := scanDevice(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, d)
	}
	return out, rows.Err()
}

// CollectorDevices perangkat aktif yang dipantau satu collector site.
func (s Store) CollectorDevices(ctx context.Context, collectorID int64) ([]Device, error) {
	rows, err := s.DB.Query(ctx, `SELECT `+deviceCols+` FROM devices WHERE enabled AND collector_id = $1 ORDER BY id`, collectorID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Device
	for rows.Next() {
		d, err := scanDevice(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, d)
	}
	return out, rows.Err()
}

func (s Store) Device(ctx context.Context, id int64) (Device, error) {
	return scanDevice(s.DB.QueryRow(ctx, `SELECT `+deviceCols+` FROM devices WHERE id = $1`, id))
}

// PingUpdate adalah hasil satu putaran ping untuk satu perangkat.
type PingUpdate struct {
	ID         int64
	RTT        *float64
	Jitter     *float64
	Loss       float64
	FailStreak int
	Status     string
	Reason     *string
	Changed    bool
	At         time.Time
}

func (s Store) SavePing(ctx context.Context, ups []PingUpdate) error {
	b := &pgx.Batch{}
	for _, u := range ups {
		b.Queue(`UPDATE devices SET last_rtt_ms = $2, last_loss_pct = $3, last_ping_at = $4, fail_streak = $5, last_jitter_ms = $9,
			status = $6, status_reason = $7, status_changed_at = CASE WHEN $8 THEN $4 ELSE status_changed_at END, updated_at = now()
			WHERE id = $1`, u.ID, u.RTT, u.Loss, u.At, u.FailStreak, u.Status, u.Reason, u.Changed, u.Jitter)
	}
	return s.DB.SendBatch(ctx, b).Close()
}

type SNMPUpdate struct {
	ID                                     int64
	OK                                     bool
	Error                                  *string
	SysName, SysDescr, SysObjectID, SysLoc *string
	Uptime                                 *int64
	At                                     time.Time
	// Untuk perangkat tanpa ping: status dinilai dari SNMP
	Status     *string
	Reason     *string
	FailStreak *int
	Changed    bool
}

func (s Store) SaveSNMP(ctx context.Context, u SNMPUpdate) error {
	_, err := s.DB.Exec(ctx, `UPDATE devices SET snmp_ok = $2, snmp_error = $3, last_snmp_at = $4,
		sys_name = COALESCE($5, sys_name), sys_descr = COALESCE($6, sys_descr), sys_object_id = COALESCE($7, sys_object_id),
		sys_location = COALESCE($8, sys_location), uptime_seconds = COALESCE($9, uptime_seconds),
		status = COALESCE($10, status), status_reason = CASE WHEN $10::varchar IS NULL THEN status_reason ELSE $11 END,
		fail_streak = COALESCE($12, fail_streak), status_changed_at = CASE WHEN $13 THEN $4 ELSE status_changed_at END,
		updated_at = now() WHERE id = $1`,
		u.ID, u.OK, u.Error, u.At, u.SysName, u.SysDescr, u.SysObjectID, u.SysLoc, u.Uptime, u.Status, u.Reason, u.FailStreak, u.Changed)
	return err
}

type InterfaceRow struct {
	Index                   int
	Name, Alias, Descr, MAC string
	Type                    int
	SpeedBps                int64
	AdminUp, OperUp         bool
	InBps, OutBps, Util     *float64 // nil = belum bisa dihitung
	ErrRate, DiscardRate    *float64 // per menit, masuk + keluar; nil = belum bisa dihitung
}

// SaveInterfaces menyimpan daftar port terbaru; port yang sudah hilang dari perangkat dihapus.
func (s Store) SaveInterfaces(ctx context.Context, deviceID int64, rows []InterfaceRow) error {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	// status port sebelumnya, untuk mencatat perubahan up/down (riwayat & deteksi naik-turun)
	prev := map[int]bool{}
	if pr, err := tx.Query(ctx, `SELECT if_index, oper_up FROM interfaces WHERE device_id = $1 AND oper_up IS NOT NULL`, deviceID); err == nil {
		for pr.Next() {
			var i int
			var up bool
			if pr.Scan(&i, &up) == nil {
				prev[i] = up
			}
		}
		pr.Close()
	} else {
		return err
	}
	idx := make([]int32, 0, len(rows))
	for _, r := range rows {
		if was, ok := prev[r.Index]; ok && was != r.OperUp && r.AdminUp {
			if _, err := tx.Exec(ctx, `INSERT INTO port_changes (device_id, if_index, up, source) VALUES ($1, $2, $3, 'poll')`, deviceID, r.Index, r.OperUp); err != nil {
				return err
			}
		}
		idx = append(idx, int32(r.Index))
		if _, err := tx.Exec(ctx, `INSERT INTO interfaces (device_id, if_index, name, alias, descr, if_type, speed_bps, admin_up, oper_up, mac, in_bps, out_bps, util_pct, err_rate, discard_rate, last_change_at, updated_at)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NULLIF($10,''),$11,$12,$13,$14,$15, now(), now())
			ON CONFLICT (device_id, if_index) DO UPDATE SET name = EXCLUDED.name, alias = EXCLUDED.alias, descr = EXCLUDED.descr,
				if_type = EXCLUDED.if_type, speed_bps = EXCLUDED.speed_bps, admin_up = EXCLUDED.admin_up, mac = EXCLUDED.mac,
				last_change_at = CASE WHEN interfaces.oper_up IS DISTINCT FROM EXCLUDED.oper_up THEN now() ELSE interfaces.last_change_at END,
				oper_up = EXCLUDED.oper_up, in_bps = EXCLUDED.in_bps, out_bps = EXCLUDED.out_bps, util_pct = EXCLUDED.util_pct,
				err_rate = EXCLUDED.err_rate, discard_rate = EXCLUDED.discard_rate, updated_at = now()`,
			deviceID, r.Index, r.Name, r.Alias, r.Descr, r.Type, r.SpeedBps, r.AdminUp, r.OperUp, r.MAC, r.InBps, r.OutBps, r.Util, r.ErrRate, r.DiscardRate); err != nil {
			return err
		}
	}
	if _, err := tx.Exec(ctx, `DELETE FROM interfaces WHERE device_id = $1 AND NOT (if_index = ANY($2))`, deviceID, idx); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

func (s Store) Event(ctx context.Context, deviceID int64, kind, msg string, duration *int64, at time.Time) error {
	if len(msg) > 500 {
		msg = msg[:500]
	}
	_, err := s.DB.Exec(ctx, `INSERT INTO events (device_id, kind, message, duration_s, at) VALUES ($1,$2,$3,$4,$5)`, deviceID, kind, msg, duration, at)
	return err
}

// RecentEvents menghitung kejadian jenis tertentu dalam selang waktu (untuk deteksi flapping).
func (s Store) RecentEvents(ctx context.Context, deviceID int64, kind string, since time.Time) (int, error) {
	var n int
	err := s.DB.QueryRow(ctx, `SELECT count(*) FROM events WHERE device_id = $1 AND kind = $2 AND at >= $3`, deviceID, kind, since).Scan(&n)
	return n, err
}

// PrunePortChanges menghapus riwayat status port lebih tua dari batas.
func (s Store) PrunePortChanges(ctx context.Context, before time.Time) (int64, error) {
	tag, err := s.DB.Exec(ctx, `DELETE FROM port_changes WHERE at < $1`, before)
	return tag.RowsAffected(), err
}

// PruneEvents menghapus kejadian lebih tua dari batas retensi.
func (s Store) PruneEvents(ctx context.Context, before time.Time) (int64, error) {
	tag, err := s.DB.Exec(ctx, `DELETE FROM events WHERE at < $1`, before)
	return tag.RowsAffected(), err
}

type NewDevice struct {
	Name, Host, Category   string
	SiteCode               string
	Ping, SNMP             bool
	SNMPVersion            string
	SNMPPort               int
	CommunityEnc           *string
	V3User, V3Level        *string
	V3AuthProto, V3AuthEnc *string
	V3PrivProto, V3PrivEnc *string
}

func (s Store) AddDevice(ctx context.Context, d NewDevice) (int64, error) {
	var siteID *int64
	if d.SiteCode != "" {
		var id int64
		if err := s.DB.QueryRow(ctx, `INSERT INTO sites (code, name) VALUES ($1, $1) ON CONFLICT (code) DO UPDATE SET updated_at = now() RETURNING id`, d.SiteCode).Scan(&id); err != nil {
			return 0, err
		}
		siteID = &id
	}
	var id int64
	err := s.DB.QueryRow(ctx, `INSERT INTO devices (name, host, category, site_id, ping_enabled, snmp_enabled, snmp_version, snmp_port,
		snmp_community_enc, snmp_v3_user, snmp_v3_level, snmp_v3_auth_proto, snmp_v3_auth_enc, snmp_v3_priv_proto, snmp_v3_priv_enc)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15) RETURNING id`,
		d.Name, d.Host, d.Category, siteID, d.Ping, d.SNMP, d.SNMPVersion, d.SNMPPort,
		d.CommunityEnc, d.V3User, d.V3Level, d.V3AuthProto, d.V3AuthEnc, d.V3PrivProto, d.V3PrivEnc).Scan(&id)
	return id, err
}

type Summary struct {
	ID                 int64
	Name, Host, Status string
	Reason, SysName    *string
	RTT, Loss          *float32
	SNMPOK             *bool
	Ports              int
}

func (s Store) List(ctx context.Context) ([]Summary, error) {
	rows, err := s.DB.Query(ctx, `SELECT d.id, d.name, d.host, d.status, d.status_reason, d.sys_name, d.last_rtt_ms, d.last_loss_pct, d.snmp_ok,
		(SELECT count(*) FROM interfaces i WHERE i.device_id = d.id) FROM devices d ORDER BY d.id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Summary
	for rows.Next() {
		var x Summary
		if err := rows.Scan(&x.ID, &x.Name, &x.Host, &x.Status, &x.Reason, &x.SysName, &x.RTT, &x.Loss, &x.SNMPOK, &x.Ports); err != nil {
			return nil, err
		}
		out = append(out, x)
	}
	return out, rows.Err()
}

type NeighborRow struct {
	LocalIfIndex                                           int
	LocalPort, Protocol, Name, Port, Chassis, IP, Platform string
}

// SaveNeighbors mengganti daftar tetangga perangkat lalu mencocokkannya dengan perangkat yang dipantau
// (lihat matchNeighbors) dan port lawannya.
func (s Store) SaveNeighbors(ctx context.Context, deviceID int64, rows []NeighborRow) error {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	if _, err := tx.Exec(ctx, `DELETE FROM neighbors WHERE device_id = $1`, deviceID); err != nil {
		return err
	}
	for _, r := range rows {
		var local *int
		if r.LocalIfIndex > 0 {
			local = &r.LocalIfIndex
		}
		if _, err := tx.Exec(ctx, `INSERT INTO neighbors (device_id, local_if_index, local_port, protocol, remote_name, remote_port, remote_chassis, remote_ip, remote_platform)
			VALUES ($1,$2,NULLIF($3,''),$4,NULLIF($5,''),NULLIF($6,''),NULLIF($7,''),NULLIF($8,''),NULLIF($9,''))`,
			deviceID, local, cut(r.LocalPort), r.Protocol, cut(r.Name), cut(r.Port), r.Chassis, r.IP, cut(r.Platform)); err != nil {
			return err
		}
	}
	if err := matchNeighbors(ctx, tx, `n.device_id = $1`, `n.device_id = $1`, deviceID); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

func cut(s string) string {
	if len(s) > 255 {
		return s[:255]
	}
	return s
}

type SensorRow struct {
	Key, Class, Group, Label, Unit, Text, Status string
	Value                                        *float64
}

// SaveSensors mengganti pembacaan sensor perangkat (sensor yang tidak lagi dilaporkan dihapus).
func (s Store) SaveSensors(ctx context.Context, deviceID int64, rows []SensorRow) error {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	keys := make([]string, 0, len(rows))
	for _, r := range rows {
		keys = append(keys, r.Key)
		if _, err := tx.Exec(ctx, `INSERT INTO device_sensors (device_id, key, class, grp, label, unit, value, text, status, updated_at)
			VALUES ($1,$2,$3,$4,$5,$6,$7,NULLIF($8,''),$9, now())
			ON CONFLICT (device_id, key) DO UPDATE SET class = EXCLUDED.class, grp = EXCLUDED.grp, label = EXCLUDED.label, unit = EXCLUDED.unit,
				value = EXCLUDED.value, text = EXCLUDED.text, status = EXCLUDED.status, updated_at = now()`,
			deviceID, r.Key, r.Class, r.Group, cut(r.Label), r.Unit, r.Value, r.Text, r.Status); err != nil {
			return err
		}
	}
	if _, err := tx.Exec(ctx, `DELETE FROM device_sensors WHERE device_id = $1 AND NOT (key = ANY($2))`, deviceID, keys); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

// Profiles membaca profil tersimpan; refresh=true bila perlu dideteksi ulang (belum ada / > 24 jam / sysObjectID berubah).
func (s Store) Profiles(ctx context.Context, deviceID int64, sysObjectID string, prevObjectID *string) (ids string, refresh bool, err error) {
	var p *string
	var at *time.Time
	if err = s.DB.QueryRow(ctx, `SELECT profiles, profiles_at FROM devices WHERE id = $1`, deviceID).Scan(&p, &at); err != nil {
		return "", true, err
	}
	if p == nil || at == nil || time.Since(*at) > 24*time.Hour || (prevObjectID != nil && *prevObjectID != sysObjectID) {
		return "", true, nil
	}
	return *p, false, nil
}

func (s Store) SetProfiles(ctx context.Context, deviceID int64, ids string) error {
	_, err := s.DB.Exec(ctx, `UPDATE devices SET profiles = $2, profiles_at = now() WHERE id = $1`, deviceID, ids)
	return err
}

// SaveIPs mengganti daftar IP interface perangkat dan menambahkan subnetnya sebagai subnet otomatis.
func (s Store) SaveIPs(ctx context.Context, deviceID int64, ips []IPRow) error {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	keep := make([]string, 0, len(ips))
	for _, ip := range ips {
		keep = append(keep, ip.IP)
		if _, err := tx.Exec(ctx, `INSERT INTO device_ips (device_id, ip, if_index, prefix, updated_at) VALUES ($1, $2::inet, $3, NULLIF($4, 0), now())
			ON CONFLICT (device_id, ip) DO UPDATE SET if_index = EXCLUDED.if_index, prefix = EXCLUDED.prefix, updated_at = now()`,
			deviceID, ip.IP, ip.IfIndex, ip.Prefix); err != nil {
			return err
		}
		// Subnet otomatis: /8–/30, bukan loopback / link-local
		if ip.Prefix >= 8 && ip.Prefix <= 30 && !strings.HasPrefix(ip.IP, "127.") && !strings.HasPrefix(ip.IP, "169.254.") {
			if _, err := tx.Exec(ctx, `INSERT INTO subnets (cidr, source) VALUES (network(($1 || '/' || $2)::inet)::cidr, 'auto') ON CONFLICT (cidr) DO NOTHING`,
				ip.IP, strconv.Itoa(ip.Prefix)); err != nil {
				return err
			}
		}
	}
	if _, err := tx.Exec(ctx, `DELETE FROM device_ips WHERE device_id = $1 AND NOT (host(ip) = ANY($2))`, deviceID, keep); err != nil {
		return err
	}
	return tx.Commit(ctx)
}

type IPRow struct {
	IP      string
	IfIndex int
	Prefix  int
}

type ARPRow struct {
	IfIndex int
	IP, MAC string
}

// SaveARP memperbarui tabel ARP yang dilaporkan router. Pergantian MAC untuk (port, IP) yang sama dicatat
// di arp_changes sebagai dasar deteksi konflik IP. Mengembalikan jumlah pergantian baru.
func (s Store) SaveARP(ctx context.Context, reporterID int64, rows []ARPRow) (int, error) {
	prev := map[string]string{}
	r, err := s.DB.Query(ctx, `SELECT if_index, host(ip), mac FROM arp_entries WHERE reporter_id = $1`, reporterID)
	if err != nil {
		return 0, err
	}
	for r.Next() {
		var ifi int
		var ip, mac string
		if err := r.Scan(&ifi, &ip, &mac); err != nil {
			r.Close()
			return 0, err
		}
		prev[strconv.Itoa(ifi)+"|"+ip] = mac
	}
	r.Close()
	b := &pgx.Batch{}
	changes := 0
	for _, a := range rows {
		k := strconv.Itoa(a.IfIndex) + "|" + a.IP
		if old, ok := prev[k]; ok && old != a.MAC {
			b.Queue(`INSERT INTO arp_changes (reporter_id, if_index, ip, old_mac, new_mac) VALUES ($1,$2,$3::inet,$4,$5)`, reporterID, a.IfIndex, a.IP, old, a.MAC)
			changes++
		}
		b.Queue(`INSERT INTO arp_entries (reporter_id, if_index, ip, mac) VALUES ($1,$2,$3::inet,$4)
			ON CONFLICT (reporter_id, if_index, ip) DO UPDATE SET
				first_seen = CASE WHEN arp_entries.mac <> EXCLUDED.mac THEN now() ELSE arp_entries.first_seen END,
				mac = EXCLUDED.mac, last_seen = now()`, reporterID, a.IfIndex, a.IP, a.MAC)
	}
	return changes, s.DB.SendBatch(ctx, b).Close()
}

// PruneIPAM menghapus entri ARP yang tidak terlihat 30 hari dan riwayat pergantian > 30 hari.
func (s Store) PruneIPAM(ctx context.Context) error {
	if _, err := s.DB.Exec(ctx, `DELETE FROM arp_entries WHERE last_seen < now() - interval '30 days'`); err != nil {
		return err
	}
	_, err := s.DB.Exec(ctx, `DELETE FROM arp_changes WHERE at < now() - interval '30 days'`)
	return err
}

// Limits adalah ambang khusus perangkat (kolom thresholds). Nilai 0 / kosong = pakai bawaan.
type Limits struct {
	LatencyMS float64
	LossPct   float64
	UtilPct   float64
	Classes   map[string][2]float64 // kelas sensor -> [peringatan, kritis]
}

// ParseLimits membaca JSON ambang; kunci tak dikenal / nilai salah diabaikan.
func ParseLimits(raw []byte) Limits {
	l := Limits{Classes: map[string][2]float64{}}
	var m map[string]json.RawMessage
	if len(raw) == 0 || json.Unmarshal(raw, &m) != nil {
		return l
	}
	for k, v := range m {
		var f float64
		var pair [2]float64
		switch k {
		case "latency_ms", "loss_pct", "util_pct":
			if json.Unmarshal(v, &f) == nil && f > 0 {
				switch k {
				case "latency_ms":
					l.LatencyMS = f
				case "loss_pct":
					l.LossPct = f
				default:
					l.UtilPct = f
				}
			}
		default:
			if json.Unmarshal(v, &pair) == nil && pair[0] != pair[1] {
				l.Classes[k] = pair
			}
		}
	}
	return l
}

func (d Device) Limits() Limits { return ParseLimits(d.ThresholdsJSON) }

type execer interface {
	Exec(ctx context.Context, sql string, args ...any) (pgconn.CommandTag, error)
}

// matchNeighbors mencocokkan tetangga LLDP/CDP ke perangkat yang dipantau, urutan keyakinan:
// IP manajemen = host atau salah satu IP perangkat (device_ips) → sysName → nama perangkat di ANTAREJA → MAC chassis = MAC
// salah satu port. Nama dibandingkan tanpa beda huruf besar/kecil, juga bagian sebelum titik pertama (FQDN).
func matchNeighbors(ctx context.Context, db execer, where, portWhere string, args ...any) error {
	if _, err := db.Exec(ctx, `
		UPDATE neighbors n SET remote_device_id = (
			SELECT d.id FROM devices d
			WHERE d.id <> n.device_id AND (
				(n.remote_ip IS NOT NULL AND (d.host = n.remote_ip OR EXISTS (SELECT 1 FROM device_ips di WHERE di.device_id = d.id AND host(di.ip) = n.remote_ip)))
				OR (n.remote_name IS NOT NULL AND d.sys_name IS NOT NULL AND (
					lower(d.sys_name) = lower(n.remote_name)
					OR lower(split_part(d.sys_name, '.', 1)) = lower(split_part(n.remote_name, '.', 1))))
				OR (n.remote_name IS NOT NULL AND (lower(d.name) = lower(n.remote_name) OR lower(d.name) = lower(split_part(n.remote_name, '.', 1))))
				OR (n.remote_chassis IS NOT NULL AND EXISTS (SELECT 1 FROM interfaces i WHERE i.device_id = d.id AND lower(i.mac) = lower(n.remote_chassis))))
			ORDER BY (n.remote_ip IS NOT NULL AND d.host = n.remote_ip) DESC,
			         (n.remote_name IS NOT NULL AND lower(COALESCE(d.sys_name, '')) = lower(n.remote_name)) DESC,
			         (n.remote_name IS NOT NULL AND lower(d.name) = lower(n.remote_name)) DESC, d.id
			LIMIT 1)
		WHERE `+where, args...); err != nil {
		return err
	}
	_, err := db.Exec(ctx, `
		UPDATE neighbors n SET remote_if_index = (
			SELECT i.if_index FROM interfaces i WHERE i.device_id = n.remote_device_id AND n.remote_port IS NOT NULL
			  AND (lower(i.name) = lower(n.remote_port) OR lower(i.descr) = lower(n.remote_port) OR lower(i.alias) = lower(n.remote_port) OR lower(i.mac) = lower(n.remote_port))
			ORDER BY i.if_index LIMIT 1)
		WHERE n.remote_device_id IS NOT NULL AND (`+portWhere+`)`, args...)
	return err
}

// RematchNeighbors mencocokkan ulang tetangga yang belum dikenal (mis. perangkatnya baru ditambahkan atau baru
// terbaca sysName-nya) tanpa menunggu perangkat pelapor di-poll lagi. Murah: hanya baris tanpa remote_device_id.
func (s Store) RematchNeighbors(ctx context.Context) error {
	return matchNeighbors(ctx, s.DB, `n.remote_device_id IS NULL`, `n.remote_if_index IS NULL`)
}
