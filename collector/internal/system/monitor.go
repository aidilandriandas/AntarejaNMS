// Package system memantau kesehatan ANTAREJA sendiri: menulis detak collector dan mengirim peringatan sistem
// (backup gagal/terlambat, disk backup hampir penuh, layanan backup berhenti, notifikasi gagal, syslog dibuang)
// ke kanal notifikasi — sekali saat muncul, pengingat tiap 6 jam selama masih aktif, dan kabar saat pulih.
package system

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/notify"
)

// Counters sumber angka dari komponen lain (boleh nil).
type Counters struct {
	LastPing func() time.Time
	LastSNMP func() time.Time
	Syslog   func() (received, stored, dropped int64)
}

type Monitor struct {
	DB      *pgxpool.Pool
	Notify  *notify.Dispatcher
	Log     *slog.Logger
	Version string
	Started time.Time
	C       Counters

	Remind time.Duration // jeda pengingat selama masih aktif (bawaan 6 jam)

	lastDropped int64
	dropHist    []dropPoint
}

type dropPoint struct {
	at time.Time
	n  int64
}

// Alert hasil penilaian satu kondisi.
type Alert struct {
	Key      string
	Active   bool
	Severity string // warning | high
	Message  string
}

var labels = map[string]string{
	"backup_failed": "Backup gagal", "backup_late": "Backup terlambat", "backup_service": "Layanan backup berhenti",
	"backup_disk": "Disk backup hampir penuh", "notify_failed": "Notifikasi gagal terkirim", "syslog_dropped": "Syslog dibuang",
	"backup_offsite": "Salinan backup ke luar server gagal",
}

// Run menulis detak lalu menilai & mengirim peringatan. Dipanggil tiap menit.
func (m *Monitor) Run(ctx context.Context) error {
	if err := m.heartbeat(ctx); err != nil {
		return err
	}
	alerts := m.evaluate(ctx)
	return m.apply(ctx, alerts)
}

func ts(f func() time.Time) any {
	if f == nil {
		return nil
	}
	t := f()
	if t.IsZero() {
		return nil
	}
	return t.Format(time.RFC3339)
}

func (m *Monitor) heartbeat(ctx context.Context) error {
	v := map[string]any{"version": m.Version, "started_at": m.Started.Format(time.RFC3339), "ping_at": ts(m.C.LastPing), "snmp_at": ts(m.C.LastSNMP)}
	if m.C.Syslog != nil {
		r, s, d := m.C.Syslog()
		v["syslog"] = map[string]int64{"received": r, "stored": s, "dropped": d}
		v["syslog_dropped_1h"] = m.dropped1h(d)
	}
	b, _ := json.Marshal(v)
	_, err := m.DB.Exec(ctx, `INSERT INTO system_status (key, value, updated_at) VALUES ('collector', $1, now())
		ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`, b)
	return err
}

// dropped1h jumlah syslog yang dibuang dalam satu jam terakhir (dari selisih penghitung kumulatif).
func (m *Monitor) dropped1h(total int64) int64 {
	now := time.Now()
	m.dropHist = append(m.dropHist, dropPoint{now, total})
	for len(m.dropHist) > 0 && now.Sub(m.dropHist[0].at) > time.Hour {
		m.dropHist = m.dropHist[1:]
	}
	return total - m.dropHist[0].n
}

func (m *Monitor) evaluate(ctx context.Context) []Alert {
	var out []Alert
	// Backup terakhir gagal
	var st, errText string
	var at *time.Time
	_ = m.DB.QueryRow(ctx, `SELECT status, COALESCE(error, ''), finished_at FROM backups WHERE status IN ('ok', 'failed') ORDER BY id DESC LIMIT 1`).Scan(&st, &errText, &at)
	out = append(out, Alert{Key: "backup_failed", Active: st == "failed", Severity: "high", Message: "Backup database terakhir gagal: " + trim(errText, 200)})

	// Backup terlambat: aktif dijadwalkan tetapi tidak ada backup berhasil dalam 36 jam (abaikan 36 jam pertama setelah pasang)
	var enabled bool
	_ = m.DB.QueryRow(ctx, `SELECT COALESCE((SELECT value::text = 'true' FROM settings WHERE key = 'backup.enabled'), true)`).Scan(&enabled)
	var lastOK *time.Time
	var firstReq *time.Time
	_ = m.DB.QueryRow(ctx, `SELECT max(finished_at) FILTER (WHERE status = 'ok'), min(requested_at) FROM backups`).Scan(&lastOK, &firstReq)
	late := enabled && time.Since(m.Started) > 36*time.Hour && (lastOK == nil || time.Since(*lastOK) > 36*time.Hour)
	msg := "Tidak ada backup database yang berhasil dalam 36 jam terakhir."
	if lastOK != nil {
		msg = fmt.Sprintf("Backup database berhasil terakhir %s (lebih dari 36 jam).", lastOK.Local().Format("02/01/2006 15.04"))
	}
	out = append(out, Alert{Key: "backup_late", Active: late, Severity: "high", Message: msg})

	// Salinan ke luar server: backup berhasil terakhir belum tersalin setelah percobaan habis, atau tertahan > 6 jam
	var offType string
	_ = m.DB.QueryRow(ctx, `SELECT COALESCE((SELECT value->>'type' FROM settings WHERE key = 'backup.offsite'), 'off')`).Scan(&offType)
	var offSt, offErr string
	var offFin *time.Time
	if offType != "off" {
		_ = m.DB.QueryRow(ctx, `SELECT COALESCE(offsite_status, 'pending'), COALESCE(offsite_error, ''), finished_at FROM backups
			WHERE status = 'ok' ORDER BY id DESC LIMIT 1`).Scan(&offSt, &offErr, &offFin)
	}
	offBad := offType != "off" && offFin != nil && offSt != "ok" && (offSt == "failed" || time.Since(*offFin) > 6*time.Hour)
	out = append(out, Alert{Key: "backup_offsite", Active: offBad, Severity: "high",
		Message: "Backup terakhir belum tersalin ke luar server: " + trim(map[bool]string{true: offErr, false: "masih tertunda lebih dari 6 jam"}[offErr != ""], 200)})

	// Layanan backup & disk folder backup (dilaporkan layanan backup tiap 5 menit)
	var raw []byte
	var upd time.Time
	if err := m.DB.QueryRow(ctx, `SELECT value, updated_at FROM system_status WHERE key = 'backup'`).Scan(&raw, &upd); err == nil {
		out = append(out, Alert{Key: "backup_service", Active: time.Since(upd) > 20*time.Minute, Severity: "high",
			Message: fmt.Sprintf("Layanan backup tidak melapor sejak %s, backup harian tidak berjalan. Cek: ./antareja.sh logs backup", upd.Local().Format("02/01 15.04"))})
		var d struct {
			Total int64 `json:"disk_total_kb"`
			Free  int64 `json:"disk_free_kb"`
		}
		if json.Unmarshal(raw, &d) == nil && d.Total > 0 {
			pct := float64(d.Free) / float64(d.Total) * 100
			low := pct < 10 || d.Free < 2*1024*1024
			out = append(out, Alert{Key: "backup_disk", Active: low, Severity: map[bool]string{true: "high", false: "warning"}[pct < 5],
				Message: fmt.Sprintf("Ruang kosong folder backup tinggal %.1f GB (%.0f%%). Hapus backup lama / perbesar disk / pindahkan ke NAS.", float64(d.Free)/1024/1024, pct)})
		}
	}

	// Notifikasi gagal: ≥ 3 kegagalan dalam 1 jam tanpa satu pun yang berhasil di kanal yang sama
	rows, err := m.DB.Query(ctx, `SELECT c.name, count(*), max(n.error) FROM notifications n JOIN notify_channels c ON c.id = n.channel_id
		WHERE n.at > now() - interval '1 hour' AND n.status = 'failed' AND c.enabled
		  AND NOT EXISTS (SELECT 1 FROM notifications s WHERE s.channel_id = n.channel_id AND s.status = 'sent' AND s.at > now() - interval '1 hour')
		GROUP BY c.name HAVING count(*) >= 3 ORDER BY c.name`)
	var bad []string
	if err == nil {
		for rows.Next() {
			var name string
			var n int
			var e *string
			if rows.Scan(&name, &n, &e) == nil {
				s := fmt.Sprintf("%s (%d× gagal", name, n)
				if e != nil {
					s += ": " + trim(*e, 120)
				}
				bad = append(bad, s+")")
			}
		}
		rows.Close()
	}
	out = append(out, Alert{Key: "notify_failed", Active: len(bad) > 0, Severity: "high", Message: "Kanal notifikasi gagal mengirim dalam 1 jam terakhir: " + strings.Join(bad, "; ")})

	// Syslog dibuang (batas laju / antrean penuh)
	if m.C.Syslog != nil {
		_, _, d := m.C.Syslog()
		n := m.dropped1hPeek(d)
		out = append(out, Alert{Key: "syslog_dropped", Active: n > 0, Severity: "warning",
			Message: fmt.Sprintf("%d pesan syslog dibuang dalam 1 jam terakhir (batas laju per perangkat / antrean penuh). Cek perangkat yang membanjiri log di halaman Log.", n)})
	}
	return out
}

func (m *Monitor) dropped1hPeek(total int64) int64 {
	if len(m.dropHist) == 0 {
		return 0
	}
	return total - m.dropHist[0].n
}

func (m *Monitor) apply(ctx context.Context, alerts []Alert) error {
	remind := m.Remind
	if remind <= 0 {
		remind = 6 * time.Hour
	}
	for _, a := range alerts {
		var active bool
		var lastSent *time.Time
		err := m.DB.QueryRow(ctx, `SELECT active, last_sent_at FROM system_alerts WHERE key = $1`, a.Key).Scan(&active, &lastSent)
		if err != nil && !a.Active {
			continue // belum pernah aktif & tidak aktif: tidak perlu baris
		}
		switch {
		case a.Active && !active: // baru muncul
			_, _ = m.DB.Exec(ctx, `INSERT INTO system_alerts (key, active, severity, message, since, last_sent_at, resolved_at) VALUES ($1, true, $2, $3, now(), NULL, NULL)
				ON CONFLICT (key) DO UPDATE SET active = true, severity = $2, message = $3, since = now(), last_sent_at = NULL, resolved_at = NULL`, a.Key, a.Severity, trim(a.Message, 500))
			m.Log.Warn("peringatan sistem", "jenis", a.Key, "pesan", a.Message)
			m.markSent(ctx, a.Key, m.send(ctx, a, false), remind)
		case a.Active && active: // masih aktif: perbarui pesan, pengingat berkala
			_, _ = m.DB.Exec(ctx, `UPDATE system_alerts SET severity = $2, message = $3 WHERE key = $1`, a.Key, a.Severity, trim(a.Message, 500))
			if lastSent == nil || time.Since(*lastSent) >= remind {
				m.markSent(ctx, a.Key, m.send(ctx, a, false), remind)
			}
		case !a.Active && active: // pulih
			_, _ = m.DB.Exec(ctx, `UPDATE system_alerts SET active = false, resolved_at = now() WHERE key = $1`, a.Key)
			m.Log.Info("peringatan sistem pulih", "jenis", a.Key)
			m.send(ctx, a, true)
		}
	}
	return nil
}

// retryAfterFail: bila tidak ada satu kanal pun yang berhasil, coba kirim lagi setelah jeda ini (bukan menunggu pengingat 6 jam).
const retryAfterFail = 15 * time.Minute

// markSent mencatat waktu kirim. Bila semua kanal gagal (ok == 0), last_sent_at dimundurkan agar dicoba lagi ±15 menit lagi.
func (m *Monitor) markSent(ctx context.Context, key string, ok int, remind time.Duration) {
	if ok > 0 {
		_, _ = m.DB.Exec(ctx, `UPDATE system_alerts SET last_sent_at = now() WHERE key = $1`, key)
		return
	}
	_, _ = m.DB.Exec(ctx, `UPDATE system_alerts SET last_sent_at = now() - make_interval(secs => $2) WHERE key = $1`, key, (remind - retryAfterFail).Seconds())
}

// send ke semua kanal aktif yang menerima severity High (kanal "Disaster saja" tidak dikirimi peringatan sistem).
// Mengembalikan jumlah kanal yang berhasil.
func (m *Monitor) send(ctx context.Context, a Alert, resolved bool) int {
	if m.Notify == nil {
		return 0
	}
	rows, err := m.DB.Query(ctx, `SELECT id FROM notify_channels WHERE enabled AND min_severity <> 'disaster'`)
	if err != nil {
		return 0
	}
	var ids []int64
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			ids = append(ids, id)
		}
	}
	rows.Close()
	label := labels[a.Key]
	var msg notify.Message
	if resolved {
		msg = notify.Message{Event: "system.resolve", Subject: "[ANTAREJA] Pulih: " + label,
			Text: "ANTAREJA NMS: masalah sistem sudah pulih: " + label + ".",
			HTML: "✅ <b>ANTAREJA NMS</b>\nMasalah sistem pulih: <b>" + esc(label) + "</b>"}
	} else {
		msg = notify.Message{Event: "system", Subject: "[ANTAREJA] Masalah sistem: " + label,
			Text: "ANTAREJA NMS: masalah sistem: " + label + "\n\n" + a.Message,
			HTML: "⚙️ <b>ANTAREJA NMS: masalah sistem</b>\n<b>" + esc(label) + "</b>\n" + esc(a.Message)}
	}
	ok := 0
	for _, id := range ids {
		if err := m.Notify.SendTo(ctx, id, msg); err != nil {
			m.Log.Warn("peringatan sistem gagal dikirim", "kanal", id, "galat", err)
		} else {
			ok++
		}
	}
	return ok
}

func esc(s string) string {
	return strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;").Replace(s)
}

func trim(s string, n int) string {
	if len(s) > n {
		return strings.ToValidUTF8(s[:n], "") + "…"
	}
	return s
}
