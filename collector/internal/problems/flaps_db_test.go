package problems

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestLoadFlapsDB(t *testing.T) {
	db := dbtest.Open(t)
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, status) VALUES ('Radio', '10.0.0.1', 'up') RETURNING id`)
	dbtest.Exec(t, db, `INSERT INTO interfaces (device_id, if_index, name, oper_up) VALUES ($1, 2, 'wlan1', true), ($1, 3, 'ether3', true), ($1, 4, 'ether4', true)`, dev)
	dbtest.Exec(t, db, `INSERT INTO monitored_ports (device_id, if_index) VALUES ($1, 2)`, dev)
	dbtest.Exec(t, db, `INSERT INTO neighbors (device_id, local_if_index, protocol, remote_name) VALUES ($1, 4, 'lldp', 'sw-pit')`, dev)
	for i := 0; i < 3; i++ {
		dbtest.Exec(t, db, `INSERT INTO port_changes (device_id, if_index, up, source) VALUES ($1, 2, $2, 'trap'), ($1, 3, $2, 'poll'), ($1, 3, NOT $2, 'poll'), ($1, 4, $2, 'poll')`, dev, i%2 == 0)
	}
	dbtest.Exec(t, db, `INSERT INTO port_changes (device_id, if_index, up, source, at) VALUES ($1, 2, true, 'poll', now() - interval '1 minute'), ($1, 4, true, 'poll', now() - interval '2 hours')`, dev)
	e := &Engine{DB: db}
	fl, limit, mins, err := e.loadFlaps(context.Background())
	if err != nil || limit != 4 || mins != 15 {
		t.Fatalf("err=%v limit=%d mins=%d", err, limit, mins)
	}
	got := map[int]PortFlap{}
	for _, f := range fl {
		got[f.IfIndex] = f
	}
	if _, ok := got[3]; ok {
		t.Fatal("port akses (tanpa pantau/tetangga/sambungan) tidak boleh dinilai")
	}
	if g := got[2]; g.Trap != 3 || g.Poll != 1 || g.Name != "wlan1" || g.Changes() != 3 {
		t.Fatalf("wlan1: %+v", g)
	}
	if g := got[4]; g.Poll != 3 {
		t.Fatalf("ether4 (punya tetangga) hanya hitung dalam jendela: %+v", g)
	}
}
