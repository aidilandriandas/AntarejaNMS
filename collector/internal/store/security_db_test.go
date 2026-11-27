package store

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

// brokenAt: id baris pertama yang hash atau rantainya tidak cocok (0 = utuh). Sama dengan pemeriksaan di web.
func brokenAt(t *testing.T, s Store) int64 {
	t.Helper()
	var id int64
	err := s.DB.QueryRow(context.Background(), `SELECT coalesce((SELECT id FROM (
		SELECT id, hash, prev_hash, lag(hash) OVER (ORDER BY id) AS before, row_number() OVER (ORDER BY id) AS n, audit_row_hash(a) AS calc FROM audit_logs a
	) x WHERE hash IS DISTINCT FROM calc OR (n > 1 AND prev_hash IS DISTINCT FROM before) ORDER BY id LIMIT 1), 0)`).Scan(&id)
	if err != nil {
		t.Fatal(err)
	}
	return id
}

func TestAuditChainDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	u := dbtest.ID(t, db, `INSERT INTO users (name, username, password, role) VALUES ('Budi', 'budi', 'x', 'engineer') RETURNING id`)
	for i := 0; i < 5; i++ {
		dbtest.Exec(t, db, `INSERT INTO audit_logs (user_id, action, description, changes) VALUES ($1, 'updated', 'uji', '{"a": [1, 2]}')`, u)
	}
	if b := brokenAt(t, s); b != 0 {
		t.Fatal("rantai baru harus utuh, rusak di", b)
	}
	// Mengubah atau menghapus ditolak database
	if _, err := db.Exec(ctx, `UPDATE audit_logs SET description = 'dipalsukan'`); err == nil {
		t.Fatal("UPDATE harus ditolak")
	}
	if _, err := db.Exec(ctx, `DELETE FROM audit_logs`); err == nil {
		t.Fatal("DELETE harus ditolak")
	}
	// Akun dihapus: user_id jadi NULL, rantai tetap utuh
	dbtest.Exec(t, db, `DELETE FROM users WHERE id = $1`, u)
	if b := brokenAt(t, s); b != 0 {
		t.Fatal("hapus akun tidak boleh memutus rantai, rusak di", b)
	}
	// Pemalsuan dengan trigger dimatikan (superuser) terdeteksi
	var mid int64
	_ = db.QueryRow(ctx, `SELECT id FROM audit_logs ORDER BY id OFFSET 2 LIMIT 1`).Scan(&mid)
	dbtest.Exec(t, db, `ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_guard`)
	dbtest.Exec(t, db, `UPDATE audit_logs SET description = 'dipalsukan' WHERE id = $1`, mid)
	if b := brokenAt(t, s); b != mid {
		t.Fatal("pemalsuan harus terdeteksi di baris", mid, "bukan", b)
	}
	dbtest.Exec(t, db, `UPDATE audit_logs SET description = 'uji' WHERE id = $1`, mid)
	var next, after int64
	_ = db.QueryRow(ctx, `SELECT id FROM audit_logs WHERE id > $1 ORDER BY id LIMIT 1`, mid).Scan(&next)
	_ = db.QueryRow(ctx, `SELECT id FROM audit_logs WHERE id > $1 ORDER BY id LIMIT 1`, next).Scan(&after)
	dbtest.Exec(t, db, `DELETE FROM audit_logs WHERE id = $1`, next)
	if b := brokenAt(t, s); b != after {
		t.Fatal("baris hilang harus terdeteksi", b)
	}
	dbtest.Exec(t, db, `ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_guard`)
}

func TestAuditPruneDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	dbtest.Exec(t, db, `INSERT INTO audit_logs (action, description, at) VALUES ('login', 'lama', now() - interval '800 days')`)
	dbtest.Exec(t, db, `INSERT INTO audit_logs (action, description, at) VALUES ('login', 'baru', now() - interval '10 days')`)
	if n, err := s.PruneAudit(ctx); err != nil || n != 0 {
		t.Fatal("tanpa batas simpan tidak ada yang dihapus", n, err)
	}
	dbtest.Exec(t, db, `INSERT INTO settings (key, value) VALUES ('audit.keep_days', '730')`)
	if n, err := s.PruneAudit(ctx); err != nil || n != 1 {
		t.Fatal("baris 800 hari harus terhapus", n, err)
	}
	// Baris di dalam batas simpan tetap ditolak walau DELETE langsung
	if _, err := db.Exec(ctx, `DELETE FROM audit_logs WHERE description = 'baru'`); err == nil {
		t.Fatal("baris dalam batas simpan tidak boleh dihapus")
	}
	if b := brokenAt(t, s); b != 0 {
		t.Fatal("setelah pemangkasan rantai sisa harus utuh", b)
	}
	var pruned int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM audit_logs WHERE action = 'pruned'`).Scan(&pruned)
	if pruned != 1 {
		t.Fatal("pemangkasan harus tercatat")
	}
}

func TestDisableInactiveUsersDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	dbtest.Exec(t, db, `INSERT INTO users (name, username, password, role, created_at, last_login_at) VALUES
		('A', 'admin1', 'x', 'admin', now() - interval '400 days', now() - interval '200 days'),
		('B', 'lama', 'x', 'engineer', now() - interval '400 days', now() - interval '200 days'),
		('C', 'noc', 'x', 'viewer', now() - interval '400 days', now() - interval '200 days'),
		('D', 'baru', 'x', 'viewer', now() - interval '5 days', NULL)`)
	// layar NOC: login lama tapi sesi "Ingat saya" masih dipakai
	dbtest.Exec(t, db, `UPDATE users SET last_seen_at = now() - interval '1 hour' WHERE username = 'noc'`)
	if names, err := s.DisableInactiveUsers(ctx); err != nil || len(names) != 0 {
		t.Fatal("pengaturan mati: tidak ada yang dinonaktifkan", names, err)
	}
	dbtest.Exec(t, db, `INSERT INTO settings (key, value) VALUES ('auth.disable_inactive_days', '90')`)
	names, err := s.DisableInactiveUsers(ctx)
	if err != nil || len(names) != 1 || names[0] != "lama" {
		t.Fatal("hanya engineer lama yang dinonaktifkan (admin terakhir dilindungi)", names, err)
	}
	var reason string
	_ = db.QueryRow(ctx, `SELECT disabled_reason FROM users WHERE username = 'lama' AND NOT active`).Scan(&reason)
	if reason == "" {
		t.Fatal("alasan harus tercatat")
	}
	var logged int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM audit_logs WHERE action = 'disabled'`).Scan(&logged)
	if logged != 1 {
		t.Fatal("penonaktifan harus tercatat di log audit")
	}
}
