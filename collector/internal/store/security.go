package store

import (
	"context"
	"fmt"
)

// settingDays membaca pengaturan angka (hari) dari tabel settings; 0 bila kosong atau bukan angka.
func (s Store) settingDays(ctx context.Context, key string) (int, error) {
	var d int
	err := s.DB.QueryRow(ctx, `SELECT coalesce((SELECT CASE WHEN jsonb_typeof(value) = 'number' THEN value::text::numeric::int END
		FROM settings WHERE key = $1), 0)`, key).Scan(&d)
	return d, err
}

// PruneAudit menghapus log audit yang lebih tua dari batas simpan (pengaturan audit.keep_days, minimal 365 hari).
// Database sendiri hanya mengizinkan DELETE untuk baris yang lebih tua dari batas itu (migrasi 0050).
// Pemangkasan dicatat sebagai baris audit baru.
func (s Store) PruneAudit(ctx context.Context) (int64, error) {
	keep, err := s.settingDays(ctx, "audit.keep_days")
	if err != nil || keep < 365 {
		return 0, err
	}
	tag, err := s.DB.Exec(ctx, `DELETE FROM audit_logs WHERE at < now() - make_interval(days => $1)`, keep)
	if err != nil {
		return 0, err
	}
	if n := tag.RowsAffected(); n > 0 {
		_, err = s.DB.Exec(ctx, `INSERT INTO audit_logs (action, description) VALUES ('pruned', $1)`,
			fmt.Sprintf("%d catatan audit lebih tua dari %d hari dihapus sesuai batas simpan", n, keep))
		return n, err
	}
	return 0, nil
}

// DisableInactiveUsers menonaktifkan akun yang tidak dipakai lebih lama dari pengaturan auth.disable_inactive_days
// (minimal 30 hari). Admin aktif terakhir tidak pernah dinonaktifkan. Mengembalikan username yang dinonaktifkan.
func (s Store) DisableInactiveUsers(ctx context.Context) ([]string, error) {
	days, err := s.settingDays(ctx, "auth.disable_inactive_days")
	if err != nil || days < 30 {
		return nil, err
	}
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	rows, err := tx.Query(ctx, `SELECT id, username, role FROM users
		WHERE active AND greatest(coalesce(last_seen_at, '-infinity'), coalesce(last_login_at, '-infinity'), created_at) < now() - make_interval(days => $1)
		ORDER BY id FOR UPDATE`, days)
	if err != nil {
		return nil, err
	}
	type cand struct {
		id         int64
		name, role string
	}
	var cs []cand
	for rows.Next() {
		var c cand
		if err := rows.Scan(&c.id, &c.name, &c.role); err != nil {
			rows.Close()
			return nil, err
		}
		cs = append(cs, c)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	var done []string
	reason := fmt.Sprintf("tidak dipakai lebih dari %d hari", days)
	for _, c := range cs {
		if c.role == "admin" {
			var left int
			if err := tx.QueryRow(ctx, `SELECT count(*) FROM users WHERE active AND role = 'admin' AND id <> $1`, c.id).Scan(&left); err != nil {
				return nil, err
			}
			if left == 0 {
				continue
			}
		}
		if _, err := tx.Exec(ctx, `UPDATE users SET active = false, disabled_reason = $2, updated_at = now() WHERE id = $1`, c.id, "Dinonaktifkan otomatis: "+reason); err != nil {
			return nil, err
		}
		if _, err := tx.Exec(ctx, `INSERT INTO audit_logs (action, subject_type, subject_id, description) VALUES ('disabled', 'User', $1, $2)`,
			c.id, fmt.Sprintf("Akun %s dinonaktifkan otomatis: %s", c.name, reason)); err != nil {
			return nil, err
		}
		done = append(done, c.name)
	}
	return done, tx.Commit(ctx)
}
