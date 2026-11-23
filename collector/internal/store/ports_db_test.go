package store

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestSaveInterfacesMencatatPerubahanDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('sw', '10.0.0.1') RETURNING id`)
	rows := func(up2, up3, admin3 bool) []InterfaceRow {
		return []InterfaceRow{{Index: 2, Name: "ether2", AdminUp: true, OperUp: up2}, {Index: 3, Name: "ether3", AdminUp: admin3, OperUp: up3}}
	}
	for _, r := range [][]InterfaceRow{rows(true, true, true), rows(true, true, true), rows(false, true, true), rows(true, false, false)} {
		if err := s.SaveInterfaces(ctx, dev, r); err != nil {
			t.Fatal(err)
		}
	}
	var n2, n3 int
	_ = db.QueryRow(ctx, `SELECT count(*) FILTER (WHERE if_index = 2), count(*) FILTER (WHERE if_index = 3) FROM port_changes WHERE source = 'poll'`).Scan(&n2, &n3)
	if n2 != 2 || n3 != 0 {
		t.Fatalf("ether2 berubah 2 kali (turun, naik), ether3 dimatikan admin tidak dihitung: dapat %d dan %d", n2, n3)
	}
}
