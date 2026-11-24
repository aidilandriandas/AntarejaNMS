package store

import (
	"context"
	"strings"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
)

func TestSaveRoutingDB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('core-r', '10.0.0.1') RETURNING id`)
	r := snmp.Routing{BGPOK: true, OSPFOK: true, LocalAS: 65000,
		BGP:  []snmp.BGPPeer{{Addr: "10.0.0.2", RemoteAS: 64512, State: 6, Admin: 2}, {Addr: "10.0.0.3", RemoteAS: 64513, State: 6, Admin: 2}},
		OSPF: []snmp.OSPFNeighbor{{Addr: "10.1.0.2", RouterID: "1.1.1.2", State: 8}}}
	if ev, err := s.SaveRouting(ctx, dev, r); err != nil || len(ev) != 0 {
		t.Fatal(ev, err)
	}
	r.BGP = r.BGP[:1]
	r.BGP[0].State = 3
	r.OSPF = nil // semua tetangga OSPF hilang: walk berhasil tetapi kosong
	ev, err := s.SaveRouting(ctx, dev, r)
	if err != nil || len(ev) != 3 {
		t.Fatal(ev, err)
	}
	if !strings.Contains(strings.Join(ev, "|"), "established ke active") {
		t.Fatal(ev)
	}
	var miss bool
	_ = db.QueryRow(ctx, `SELECT missing FROM ospf_neighbors WHERE device_id = $1`, dev).Scan(&miss)
	if !miss {
		t.Fatal("tetangga OSPF harus ditandai hilang")
	}
	// tabel tidak terbaca (galat SNMP): tidak ada yang ditandai
	if ev, _ := s.SaveRouting(ctx, dev, snmp.Routing{}); len(ev) != 0 {
		t.Fatal(ev)
	}
}
