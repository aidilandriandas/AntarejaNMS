// Package dbtest menyiapkan database PostgreSQL sungguhan untuk tes integrasi collector.
// Tes dilewati bila ANTAREJA_TEST_DATABASE_URL kosong. Nama database WAJIB berakhiran _test (skema public dihapus
// dan dibangun ulang dari db/migrations). Jalankan dengan `go test -p 1 ./...` supaya paket tidak saling menimpa.
package dbtest

import (
	"context"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/migrate"
)

// Open mengembalikan pool ke database tes yang baru dimigrasi.
func Open(t *testing.T) *pgxpool.Pool {
	t.Helper()
	url := os.Getenv("ANTAREJA_TEST_DATABASE_URL")
	if url == "" {
		t.Skip("set ANTAREJA_TEST_DATABASE_URL (database berakhiran _test) untuk tes integrasi database")
	}
	cfg, err := pgxpool.ParseConfig(url)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(cfg.ConnConfig.Database, "_test") {
		t.Fatalf("tes integrasi hanya boleh memakai database berakhiran _test, bukan %q", cfg.ConnConfig.Database)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	db, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(db.Close)
	if _, err := db.Exec(ctx, `DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;`); err != nil {
		t.Fatal(err)
	}
	_, self, _, _ := runtime.Caller(0)
	dir := filepath.Join(filepath.Dir(self), "..", "..", "..", "db", "migrations")
	if _, err := migrate.Run(ctx, db, dir); err != nil {
		t.Fatal(err)
	}
	return db
}

// Exec menjalankan SQL penyiapan data dan menggagalkan tes bila error.
func Exec(t *testing.T, db *pgxpool.Pool, sql string, args ...any) {
	t.Helper()
	if _, err := db.Exec(context.Background(), sql, args...); err != nil {
		t.Fatalf("%v\n%s", err, sql)
	}
}

// ID menjalankan INSERT ... RETURNING id.
func ID(t *testing.T, db *pgxpool.Pool, sql string, args ...any) int64 {
	t.Helper()
	var id int64
	if err := db.QueryRow(context.Background(), sql, args...).Scan(&id); err != nil {
		t.Fatalf("%v\n%s", err, sql)
	}
	return id
}
