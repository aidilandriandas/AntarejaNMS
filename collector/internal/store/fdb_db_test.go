package store_test

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

func TestMACLocationDanPindah(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := store.Store{DB: db}
	core := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('core', '10.0.0.1', 'core') RETURNING id`)
	acc := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('sw-a', '10.0.0.2', 'access') RETURNING id`)
	acc2 := dbtest.ID(t, db, `INSERT INTO devices (name, host, category) VALUES ('sw-b', '10.0.0.3', 'access') RETURNING id`)
	// uplink core port 1 ke sw-a port 24 lewat LLDP
	dbtest.Exec(t, db, `INSERT INTO neighbors (device_id, local_if_index, protocol, remote_name) VALUES ($1, 1, 'lldp', 'sw-a'), ($2, 24, 'lldp', 'core')`, core, acc)
	cam := "00:11:22:33:44:55"
	if err := s.SaveFDB(ctx, core, []store.FDBRow{{IfIndex: 1, VLAN: 10, MAC: cam}}); err != nil {
		t.Fatal(err)
	}
	if err := s.SaveFDB(ctx, acc, []store.FDBRow{{IfIndex: 5, VLAN: 10, MAC: cam}, {IfIndex: 24, VLAN: 10, MAC: "00:aa:aa:aa:aa:aa"}}); err != nil {
		t.Fatal(err)
	}
	if n, err := s.RecomputeMACLocations(ctx); err != nil || n != 0 {
		t.Fatalf("%d %v", n, err)
	}
	var dev int64
	var ifi int
	if err := db.QueryRow(ctx, `SELECT device_id, if_index FROM mac_locations WHERE mac = $1`, cam).Scan(&dev, &ifi); err != nil || dev != acc || ifi != 5 {
		t.Fatalf("kamera di sw-a port 5, dapat %d/%d %v", dev, ifi, err)
	}
	var none int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM mac_locations WHERE mac = '00:aa:aa:aa:aa:aa'`).Scan(&none)
	if none != 0 {
		t.Fatal("MAC yang hanya terlihat di port LLDP tidak diberi lokasi")
	}
	// kamera dipindah ke sw-b port 9: entri lama menua
	dbtest.Exec(t, db, `UPDATE fdb_entries SET last_seen = now() - interval '1 hour' WHERE mac = $1`, cam)
	if err := s.SaveFDB(ctx, acc2, []store.FDBRow{{IfIndex: 9, VLAN: 10, MAC: cam}}); err != nil {
		t.Fatal(err)
	}
	if n, err := s.RecomputeMACLocations(ctx); err != nil || n != 1 {
		t.Fatalf("satu pindah: %d %v", n, err)
	}
	var from, to int64
	if err := db.QueryRow(ctx, `SELECT from_device_id, to_device_id FROM mac_moves WHERE mac = $1`, cam).Scan(&from, &to); err != nil || from != acc || to != acc2 {
		t.Fatalf("riwayat pindah %d → %d %v", from, to, err)
	}
}
