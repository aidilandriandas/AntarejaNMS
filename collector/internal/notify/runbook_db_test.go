package notify

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestRunbookFor(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	site := dbtest.ID(t, db, `INSERT INTO sites (code, name) VALUES ('PIT-R', 'Pit R') RETURNING id`)
	radio := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id, category) VALUES ('ptp-r', '10.0.9.1', $1, 'ptp') RETURNING id`, site)
	other := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('sw', '10.0.9.2', 'access') RETURNING id`)
	if RunbookFor(ctx, db, radio, "down") != nil {
		t.Fatal("belum ada runbook")
	}
	dbtest.Exec(t, db, `INSERT INTO runbooks (title, body) VALUES ('Umum', 'Cek dulu di Topologi')`)
	dbtest.Exec(t, db, `INSERT INTO runbooks (title, body, problem_kind) VALUES ('Perangkat down', 'Ping dari Alat diagnosa', 'down')`)
	dbtest.Exec(t, db, `INSERT INTO runbooks (title, body, problem_kind, category) VALUES ('Radio PTP down', E'\n1. Cek PoE di menara\n\n2. Cek sisi lawan\n3. a\n4. b\n5. c\n6. tidak ikut', 'down', 'ptp')`)
	dbtest.Exec(t, db, `INSERT INTO runbooks (title, body, category, site_id, enabled) VALUES ('Radio Pit R (mati)', 'x', 'ptp', $1, false)`, site)

	if r := RunbookFor(ctx, db, radio, "down"); r == nil || r.Title != "Radio PTP down" || len(r.Steps) != 5 || r.Steps[0] != "1. Cek PoE di menara" {
		t.Fatalf("radio down memakai runbook kategori+jenis, 5 langkah tanpa baris kosong: %+v", r)
	}
	if r := RunbookFor(ctx, db, radio, "latency"); r == nil || r.Title != "Umum" {
		t.Fatalf("jenis lain jatuh ke runbook umum: %+v", r)
	}
	if r := RunbookFor(ctx, db, other, "down"); r == nil || r.Title != "Perangkat down" {
		t.Fatalf("kategori lain: %+v", r)
	}

	p := Problem{Severity: "high", Title: "ptp-r down", Device: "ptp-r", Host: "10.0.9.1", StartedAt: time.Now(), Runbook: RunbookFor(ctx, db, radio, "down")}
	m := Single("open", p, 0, "https://nms.local")
	if !strings.Contains(m.Text, "Langkah penanganan (Radio PTP down):\n1. Cek PoE di menara") || !strings.Contains(m.HTML, "https://nms.local/runbook/") || strings.Contains(m.Text, "tidak ikut") {
		t.Fatalf("pesan memuat langkah: %s", m.Text)
	}
	if strings.Contains(Single("resolve", p, 0, "").Text, "Langkah") {
		t.Fatal("pesan pulih tanpa runbook")
	}
}
