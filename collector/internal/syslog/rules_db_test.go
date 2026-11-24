package syslog

import (
	"context"
	"io"
	"log/slog"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestAturanPolaDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('rt-log', '10.9.9.9') RETURNING id`)
	rid := dbtest.ID(t, db, `INSERT INTO syslog_rules (name, pattern, min_count, window_s, severity) VALUES ('Gagal login', 'login failure', 2, 60, 'average') RETURNING id`)
	s := &Server{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil))}
	s.refresh(ctx)
	now := time.Now()
	msg := func(d time.Duration, txt string) Message {
		return Message{Received: now.Add(d), Source: "10.9.9.9", Severity: 4, Facility: -1, Text: txt}
	}
	if err := s.store(ctx, []Message{msg(0, "login failure for admin"), msg(time.Second, "ok biasa")}); err != nil {
		t.Fatal(err)
	}
	var n int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM syslog_rule_hits`).Scan(&n)
	if n != 0 {
		t.Fatal("baru sekali, belum terpicu")
	}
	if err := s.store(ctx, []Message{msg(2*time.Second, "LOGIN FAILURE for root")}); err != nil {
		t.Fatal(err)
	}
	var hits int
	var last string
	if err := db.QueryRow(ctx, `SELECT hits, last_message FROM syslog_rule_hits WHERE rule_id = $1 AND device_id = $2`, rid, dev).Scan(&hits, &last); err != nil {
		t.Fatal(err)
	}
	if hits != 2 || last != "LOGIN FAILURE for root" {
		t.Fatal(hits, last)
	}
}
