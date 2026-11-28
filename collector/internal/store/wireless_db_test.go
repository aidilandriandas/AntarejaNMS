package store

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestSaveWirelessDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	ap := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('AP PIT-A', '10.17.36.10', 'wireless') RETURNING id`)
	cpe := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('CPE HAULING', '10.17.36.120', 'ptp') RETURNING id`)
	cpe2 := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('CPE WORKSHOP', '10.17.36.130', 'ptp') RETURNING id`)
	// CPE kedua dikenali dari MAC interface radionya (IP station tidak dilaporkan)
	dbtest.Exec(t, db, `INSERT INTO interfaces (device_id, if_index, name, mac) VALUES ($1, 5, 'ath0', '18:5a:d6:00:00:02')`, cpe2)
	f := func(v float64) *float64 { return &v }
	rows := []WirelessRow{
		{Kind: "airmax", MAC: "18:5a:d6:00:00:01", Name: "CPE HAULING", IP: "10.17.36.120", Signal: f(-62), CCQ: f(94), Connected: true},
		{Kind: "airmax", MAC: "18:5a:d6:00:00:02", Signal: f(-81), Connected: true},
		{Kind: "airmax", MAC: "18:5a:d6:00:00:03", Name: "laptop tamu", Connected: true},
	}
	if lost, back, err := s.SaveWireless(ctx, ap, rows); err != nil || len(lost)+len(back) != 0 {
		t.Fatal(err, lost, back)
	}
	var r1, r2 *int64
	_ = db.QueryRow(ctx, `SELECT remote_device_id FROM wireless_links WHERE device_id = $1 AND mac = '18:5a:d6:00:00:01'`, ap).Scan(&r1)
	_ = db.QueryRow(ctx, `SELECT remote_device_id FROM wireless_links WHERE device_id = $1 AND mac = '18:5a:d6:00:00:02'`, ap).Scan(&r2)
	if r1 == nil || *r1 != cpe || r2 == nil || *r2 != cpe2 {
		t.Fatal("station harus dicocokkan lewat IP dan MAC", r1, r2)
	}
	var n int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM neighbors WHERE device_id = $1 AND protocol = 'wifi' AND remote_device_id IS NOT NULL`, ap).Scan(&n)
	if n != 2 {
		t.Fatal("2 tetangga wifi untuk topologi", n)
	}
	// LLDP tidak menghapus tetangga wifi
	if err := s.SaveNeighbors(ctx, ap, []NeighborRow{{Protocol: "lldp", Name: "core"}}); err != nil {
		t.Fatal(err)
	}
	_ = db.QueryRow(ctx, `SELECT count(*) FROM neighbors WHERE device_id = $1 AND protocol = 'wifi'`, ap).Scan(&n)
	if n != 3 {
		t.Fatal("tetangga wifi harus tetap ada setelah LLDP", n)
	}
	// station kedua hilang: terputus, bukan dihapus; tetangga wifi-nya hilang
	lost, _, err := s.SaveWireless(ctx, ap, []WirelessRow{rows[0], rows[2]})
	if err != nil || len(lost) != 1 || lost[0] != "18:5a:d6:00:00:02" {
		t.Fatal("harus tercatat terputus", lost, err)
	}
	var conn bool
	_ = db.QueryRow(ctx, `SELECT connected FROM wireless_links WHERE device_id = $1 AND mac = '18:5a:d6:00:00:02'`, ap).Scan(&conn)
	_ = db.QueryRow(ctx, `SELECT count(*) FROM neighbors WHERE device_id = $1 AND protocol = 'wifi'`, ap).Scan(&n)
	if conn || n != 2 {
		t.Fatal("terputus tetap disimpan, tetangga wifi berkurang", conn, n)
	}
	// kembali lagi
	_, back, _ := s.SaveWireless(ctx, ap, rows)
	if len(back) != 1 {
		t.Fatal("harus tercatat tersambung lagi", back)
	}
}

func TestSaveWirelessMikrotikARPDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	ap := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('AP MT', '10.17.60.1', 'wireless') RETURNING id`)
	st := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('STATION MT-18', '10.17.60.18', 'ptp') RETURNING id`)
	// registration table MikroTik tidak punya IP; ARP router menyimpan pasangan MAC dan IP
	dbtest.Exec(t, db, `INSERT INTO arp_entries (reporter_id, if_index, ip, mac) VALUES ($1, 5, '10.17.60.18', '4c:5e:0c:01:02:03')`, ap)
	sig := -67.0
	if _, _, err := s.SaveWireless(ctx, ap, []WirelessRow{{Kind: "mikrotik", MAC: "4c:5e:0c:01:02:03", Name: "STATION-MT18", Signal: &sig, Connected: true}}); err != nil {
		t.Fatal(err)
	}
	var ip string
	var rem *int64
	_ = db.QueryRow(ctx, `SELECT ip, remote_device_id FROM wireless_links WHERE device_id = $1`, ap).Scan(&ip, &rem)
	if ip != "10.17.60.18" || rem == nil || *rem != st {
		t.Fatal("IP dari ARP dan perangkat lawan harus terisi", ip, rem)
	}
}
