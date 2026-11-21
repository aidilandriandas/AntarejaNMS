// Package store membaca dan menulis data perangkat di PostgreSQL.
package store

import (
	"context"
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
}

type Store struct{ DB *pgxpool.Pool }

const deviceCols = `id, name, host, ping_enabled, snmp_enabled, snmp_version, snmp_port,
	snmp_community_enc, snmp_v3_user, snmp_v3_level, snmp_v3_auth_proto, snmp_v3_auth_enc, snmp_v3_priv_proto, snmp_v3_priv_enc,
	status, status_changed_at, fail_streak, last_rtt_ms, snmp_ok, last_snmp_at, uptime_seconds, sys_object_id`

func scanDevice(r pgx.Row) (Device, error) {
	var d Device
	err := r.Scan(&d.ID, &d.Name, &d.Host, &d.PingEnabled, &d.SNMPEnabled, &d.SNMPVersion, &d.SNMPPort,
		&d.CommunityEnc, &d.V3User, &d.V3Level, &d.V3AuthProto, &d.V3AuthEnc, &d.V3PrivProto, &d.V3PrivEnc,
		&d.Status, &d.StatusChangedAt, &d.FailStreak, &d.LastRTT, &d.SNMPOK, &d.LastSNMPAt, &d.UptimeSeconds, &d.SysObjectID)
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

func (s Store) Device(ctx context.Context, id int64) (Device, error) {
	return scanDevice(s.DB.QueryRow(ctx, `SELECT `+deviceCols+` FROM devices WHERE id = $1`, id))
}

// PingUpdate adalah hasil satu putaran ping untuk satu perangkat.
type PingUpdate struct {
	ID         int64
	RTT        *float64
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
		b.Queue(`UPDATE devices SET last_rtt_ms = $2, last_loss_pct = $3, last_ping_at = $4, fail_streak = $5,
			status = $6, status_reason = $7, status_changed_at = CASE WHEN $8 THEN $4 ELSE status_changed_at END, updated_at = now()
			WHERE id = $1`, u.ID, u.RTT, u.Loss, u.At, u.FailStreak, u.Status, u.Reason, u.Changed)
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
}

// SaveInterfaces menyimpan daftar port terbaru; port yang sudah hilang dari perangkat dihapus.
func (s Store) SaveInterfaces(ctx context.Context, deviceID int64, rows []InterfaceRow) error {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	idx := make([]int32, 0, len(rows))
	for _, r := range rows {
		idx = append(idx, int32(r.Index))
		if _, err := tx.Exec(ctx, `INSERT INTO interfaces (device_id, if_index, name, alias, descr, if_type, speed_bps, admin_up, oper_up, mac, in_bps, out_bps, util_pct, last_change_at, updated_at)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NULLIF($10,''),$11,$12,$13, now(), now())
			ON CONFLICT (device_id, if_index) DO UPDATE SET name = EXCLUDED.name, alias = EXCLUDED.alias, descr = EXCLUDED.descr,
				if_type = EXCLUDED.if_type, speed_bps = EXCLUDED.speed_bps, admin_up = EXCLUDED.admin_up, mac = EXCLUDED.mac,
				last_change_at = CASE WHEN interfaces.oper_up IS DISTINCT FROM EXCLUDED.oper_up THEN now() ELSE interfaces.last_change_at END,
				oper_up = EXCLUDED.oper_up, in_bps = EXCLUDED.in_bps, out_bps = EXCLUDED.out_bps, util_pct = EXCLUDED.util_pct, updated_at = now()`,
			deviceID, r.Index, r.Name, r.Alias, r.Descr, r.Type, r.SpeedBps, r.AdminUp, r.OperUp, r.MAC, r.InBps, r.OutBps, r.Util); err != nil {
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
// (berdasarkan IP manajemen = host, sysName, atau MAC chassis = MAC salah satu port) dan port lawannya.
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
	// Cocokkan tetangga ke perangkat yang dipantau
	if _, err := tx.Exec(ctx, `
		UPDATE neighbors n SET remote_device_id = (
			SELECT d.id FROM devices d
			WHERE d.id <> n.device_id AND (
				(n.remote_ip IS NOT NULL AND d.host = n.remote_ip)
				OR (n.remote_name IS NOT NULL AND d.sys_name IS NOT NULL AND (
					lower(d.sys_name) = lower(n.remote_name)
					OR lower(split_part(d.sys_name, '.', 1)) = lower(split_part(n.remote_name, '.', 1))))
				OR (n.remote_chassis IS NOT NULL AND EXISTS (SELECT 1 FROM interfaces i WHERE i.device_id = d.id AND i.mac = n.remote_chassis)))
			ORDER BY (n.remote_ip IS NOT NULL AND d.host = n.remote_ip) DESC LIMIT 1)
		WHERE n.device_id = $1`, deviceID); err != nil {
		return err
	}
	if _, err := tx.Exec(ctx, `
		UPDATE neighbors n SET remote_if_index = (
			SELECT i.if_index FROM interfaces i WHERE i.device_id = n.remote_device_id AND n.remote_port IS NOT NULL
			  AND (lower(i.name) = lower(n.remote_port) OR lower(i.descr) = lower(n.remote_port) OR lower(i.alias) = lower(n.remote_port) OR i.mac = lower(n.remote_port))
			ORDER BY i.if_index LIMIT 1)
		WHERE n.device_id = $1 AND n.remote_device_id IS NOT NULL`, deviceID); err != nil {
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
