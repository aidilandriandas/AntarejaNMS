package store

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
)

func TestSaveL2DB(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	s := Store{DB: db}
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('sw-l2', '10.0.3.1') RETURNING id`)
	if err := s.SaveInterfaces(ctx, dev, []InterfaceRow{{Index: 1, Name: "gi1", AdminUp: true, OperUp: true}, {Index: 2, Name: "gi2", AdminUp: true, OperUp: true}}); err != nil {
		t.Fatal(err)
	}
	l := snmp.L2{VLANsOK: true, VLANs: []snmp.VLAN{{ID: 1, Name: "default"}, {ID: 20, Name: "CCTV"}}, PVID: map[int]int{1: 20, 2: 1},
		STP: &snmp.STP{Root: "32768/00:11:22:33:44:55", TopChanges: 10, SinceChangeS: 3600}, PortSTP: map[int]int{1: 5, 2: 2}}
	if ev, err := s.SaveL2(ctx, dev, l, time.Now()); err != nil || len(ev) != 0 {
		t.Fatal(ev, err)
	}
	l.VLANs = l.VLANs[1:]
	l.STP = &snmp.STP{Root: "4096/00:aa:bb:cc:dd:ee", TopChanges: 13, SinceChangeS: 5}
	ev, err := s.SaveL2(ctx, dev, l, time.Now())
	if err != nil || len(ev) != 2 || !strings.Contains(ev[0], "3 kali") || !strings.Contains(ev[1], "4096/00:aa") {
		t.Fatal(ev, err)
	}
	var n, pvid, st int
	_ = db.QueryRow(ctx, `SELECT (SELECT count(*) FROM device_vlans WHERE device_id = $1), pvid, stp_state FROM interfaces WHERE device_id = $1 AND if_index = 2`, dev).Scan(&n, &pvid, &st)
	if n != 1 || pvid != 1 || st != 2 {
		t.Fatal(n, pvid, st)
	}
}
