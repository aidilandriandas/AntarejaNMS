package offsite

import (
	"bytes"
	"context"
	"crypto/md5"
	"encoding/base64"
	"errors"
	"io"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
)

type memTarget struct {
	files map[string][]byte
	fail  bool
}

func (m *memTarget) Put(_ context.Context, name string, r io.Reader, size int64, md5b64 string) error {
	if m.fail {
		return errors.New("jaringan putus")
	}
	b, _ := io.ReadAll(r)
	sum := md5.Sum(b)
	if int64(len(b)) != size || base64.StdEncoding.EncodeToString(sum[:]) != md5b64 {
		return errors.New("isi rusak")
	}
	m.files[name] = b
	return nil
}
func (m *memTarget) List(context.Context) ([]string, error) {
	var out []string
	for k := range m.files {
		out = append(out, k)
	}
	sort.Strings(out)
	return out, nil
}
func (m *memTarget) Delete(_ context.Context, n string) error { delete(m.files, n); return nil }
func (m *memTarget) Close() error                             { return nil }
func (m *memTarget) Describe() string                         { return "memori" }

func TestRunnerSalinDanRetensiDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	dir := t.TempDir()
	mt := &memTarget{files: map[string][]byte{}}
	// dua salinan lama sudah ada di tujuan; retensi 2 -> yang tertua harus hilang setelah unggahan baru
	mt.files["antareja-20261001-013000.dump"] = []byte("a")
	mt.files["antareja-20261001-013000.json"] = []byte("{}")
	mt.files["antareja-20261002-013000.dump"] = []byte("b")
	for _, n := range []string{"antareja-20261103-013000", "antareja-20261104-013000"} {
		_ = os.WriteFile(filepath.Join(dir, n+".dump"), bytes.Repeat([]byte("x"), 1000), 0o600)
		_ = os.WriteFile(filepath.Join(dir, n+".json"), []byte(`{"file":"`+n+`.dump"}`), 0o600)
		dbtest.Exec(t, db, `INSERT INTO backups (kind, status, file) VALUES ('scheduled', 'ok', $1)`, n+".dump")
	}
	dbtest.Exec(t, db, `INSERT INTO backups (kind, status, file) VALUES ('manual', 'ok', 'antareja-20261105-013000.dump')`) // berkas tidak ada
	r := &Runner{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Dir: dir}

	// putaran dengan tujuan palsu (load dilewati: panggil langkah inti langsung)
	run := func() {
		rows, _ := db.Query(ctx, `SELECT id, file FROM backups WHERE status = 'ok' AND (offsite_status IS NULL OR offsite_status = 'failed') ORDER BY id DESC`)
		type j struct {
			id   int64
			file string
		}
		var js []j
		for rows.Next() {
			var x j
			_ = rows.Scan(&x.id, &x.file)
			js = append(js, x)
		}
		rows.Close()
		for _, x := range js {
			st := "ok"
			if err := r.copyOne(ctx, mt, x.file); err != nil {
				st = "failed"
			}
			dbtest.Exec(t, db, `UPDATE backups SET offsite_status = $2 WHERE id = $1`, x.id, st)
		}
	}
	run()
	var ok, failed int
	_ = db.QueryRow(ctx, `SELECT count(*) FILTER (WHERE offsite_status = 'ok'), count(*) FILTER (WHERE offsite_status = 'failed') FROM backups`).Scan(&ok, &failed)
	if ok != 2 || failed != 1 {
		t.Fatalf("ok=%d gagal=%d", ok, failed)
	}
	if len(mt.files["antareja-20261104-013000.dump"]) != 1000 || mt.files["antareja-20261104-013000.json"] == nil {
		t.Fatal("dump dan manifest harus tersalin utuh")
	}
	if err := prune(ctx, mt, 2); err != nil {
		t.Fatal(err)
	}
	names, _ := mt.List(ctx)
	want := []string{"antareja-20261103-013000.dump", "antareja-20261103-013000.json", "antareja-20261104-013000.dump", "antareja-20261104-013000.json"}
	if len(names) != len(want) {
		t.Fatalf("retensi 2: %v", names)
	}
	for i := range want {
		if names[i] != want[i] {
			t.Fatalf("retensi 2: %v", names)
		}
	}
	// nama berkas aneh dari database tidak boleh keluar dari folder backup
	if err := r.copyOne(ctx, mt, "../../etc/passwd"); err == nil {
		t.Fatal("jalur keluar folder harus ditolak")
	}
}

func TestRunPenuhLewatSFTPDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	addr, fp := sshd(t)
	host, port, _ := net.SplitHostPort(addr)
	box, err := secret.New("base64:" + base64.StdEncoding.EncodeToString(bytes.Repeat([]byte("k"), 32)))
	if err != nil {
		t.Fatal(err)
	}
	enc, _ := box.Encrypt(`{"password":"rahasia"}`)
	dbtest.Exec(t, db, `UPDATE settings SET value = jsonb_build_object('type', 'sftp', 'host', $1::text, 'port', $2::int, 'user', 'backup', 'dir', '/nms', 'keep', 1) WHERE key = 'backup.offsite'`, host, port)
	dbtest.Exec(t, db, `INSERT INTO settings (key, value) VALUES ('backup.offsite_secret', to_jsonb($1::text))`, enc)
	dir := t.TempDir()
	for _, n := range []string{"antareja-20261103-013000", "antareja-20261104-013000"} {
		_ = os.WriteFile(filepath.Join(dir, n+".dump"), []byte("isi "+n), 0o600)
		dbtest.Exec(t, db, `INSERT INTO backups (kind, status, file) VALUES ('scheduled', 'ok', $1)`, n+".dump")
	}
	r := &Runner{DB: db, Box: box, Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Dir: dir}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	var ok int
	var hk string
	_ = db.QueryRow(ctx, `SELECT count(*) FROM backups WHERE offsite_status = 'ok'`).Scan(&ok)
	_ = db.QueryRow(ctx, `SELECT value #>> '{}' FROM settings WHERE key = 'backup.offsite_hostkey'`).Scan(&hk)
	if ok != 2 || hk != fp {
		t.Fatalf("tersalin %d, host key %q (seharusnya %q)", ok, hk, fp)
	}
	if res := r.Test(ctx); !res.OK || res.HostKey != fp {
		t.Fatalf("uji koneksi: %+v", res)
	}
	// host key server berubah: backup baru gagal tersalin dan alasannya tercatat
	dbtest.Exec(t, db, `UPDATE settings SET value = '"SHA256:lain"' WHERE key = 'backup.offsite_hostkey'`)
	_ = os.WriteFile(filepath.Join(dir, "antareja-20261105-013000.dump"), []byte("x"), 0o600)
	dbtest.Exec(t, db, `INSERT INTO backups (kind, status, file) VALUES ('scheduled', 'ok', 'antareja-20261105-013000.dump')`)
	_ = r.Run(ctx)
	var st, msg string
	var tries int
	_ = db.QueryRow(ctx, `SELECT offsite_status, offsite_error, offsite_tries FROM backups WHERE file = 'antareja-20261105-013000.dump'`).Scan(&st, &msg, &tries)
	if st != "failed" || tries != 1 || !strings.Contains(msg, "host key") {
		t.Fatalf("status=%s tries=%d galat=%q", st, tries, msg)
	}
	// berkas yang sudah hilang dari folder: gagal permanen, tidak dicoba ulang
	dbtest.Exec(t, db, `INSERT INTO backups (kind, status, file) VALUES ('scheduled', 'ok', 'antareja-20260101-013000.dump')`)
	dbtest.Exec(t, db, `UPDATE backups SET offsite_status = 'ok' WHERE file = 'antareja-20261105-013000.dump'`)
	_ = r.Run(ctx)
	_ = db.QueryRow(ctx, `SELECT offsite_tries FROM backups WHERE file = 'antareja-20260101-013000.dump'`).Scan(&tries)
	if tries != 6 {
		t.Fatalf("berkas hilang harus langsung final, tries=%d", tries)
	}
	dbtest.Exec(t, db, `UPDATE backups SET offsite_status = 'failed' WHERE file = 'antareja-20261105-013000.dump'`)
	tries = 1
	// coba ulang tidak langsung (jeda 10 menit x percobaan)
	_ = r.Run(ctx)
	_ = db.QueryRow(ctx, `SELECT offsite_tries FROM backups WHERE file = 'antareja-20261105-013000.dump'`).Scan(&tries)
	if tries != 1 {
		t.Fatalf("dicoba ulang terlalu cepat: %d", tries)
	}
}
