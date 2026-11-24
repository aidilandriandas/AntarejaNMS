package modbus

import (
	"context"
	"io"
	"log/slog"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

func TestRunnerSensor(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	srv := newFake(t)
	srv.input[0x3104] = 1150 // 11,50 V: kritis
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host, modbus_port, modbus_unit) VALUES ('menara-a', '127.0.0.1', $1, 1) RETURNING id`, srv.port)
	pt := dbtest.ID(t, db, `INSERT INTO modbus_points (device_id, label, class, unit, fn, address, scale, warn_low, crit_low) VALUES ($1, 'Baterai', 'voltage', 'V', 4, 12548, 0.01, 12.2, 11.8) RETURNING id`, dev)
	off := dbtest.ID(t, db, `INSERT INTO modbus_points (device_id, label, class, fn, address, enabled) VALUES ($1, 'Mati', 'other', 4, 1, false) RETURNING id`, dev)
	_ = off
	st := store.Store{DB: db}
	r := &Runner{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Store: st}
	if err := r.Run(ctx); err != nil {
		t.Fatal(err)
	}
	var val float64
	var status, grp string
	if err := db.QueryRow(ctx, `SELECT value, status, grp FROM device_sensors WHERE device_id = $1 AND key = 'mb:' || $2::bigint`, dev, pt).Scan(&val, &status, &grp); err != nil {
		t.Fatal(err)
	}
	if val != 11.5 || status != "crit" || grp != "daya" {
		t.Fatalf("sensor baterai: %v %s %s", val, status, grp)
	}
	// putaran SNMP perangkat yang sama tidak menghapus sensor Modbus
	v := 40.0
	if err := st.SaveSensors(ctx, dev, []store.SensorRow{{Key: "cpu", Class: "cpu", Group: "sistem", Label: "CPU", Unit: "%", Value: &v, Status: "ok"}}); err != nil {
		t.Fatal(err)
	}
	var n int
	_ = db.QueryRow(ctx, `SELECT count(*) FROM device_sensors WHERE device_id = $1`, dev).Scan(&n)
	if n != 2 {
		t.Fatalf("sensor SNMP dan Modbus berdampingan, dapat %d", n)
	}
	// titik dihapus → sensornya ikut hilang pada putaran berikut
	dbtest.Exec(t, db, `DELETE FROM modbus_points WHERE id = $1`, pt)
	dbtest.Exec(t, db, `INSERT INTO modbus_points (device_id, label, class, fn, address) VALUES ($1, 'Lain', 'other', 4, 12548)`, dev)
	_ = r.Run(ctx)
	_ = db.QueryRow(ctx, `SELECT count(*) FROM device_sensors WHERE device_id = $1 AND key = 'mb:' || $2::bigint`, dev, pt).Scan(&n)
	if n != 0 {
		t.Fatal("sensor titik yang dihapus harus hilang")
	}
	// perangkat tidak menjawab: galat dicatat, sensor lama dibiarkan
	dbtest.Exec(t, db, `UPDATE devices SET modbus_port = 1 WHERE id = $1`, dev)
	_ = r.Run(ctx)
	var msg *string
	_ = db.QueryRow(ctx, `SELECT modbus_error FROM devices WHERE id = $1`, dev).Scan(&msg)
	if msg == nil || *msg == "" {
		t.Fatal("galat koneksi dicatat di perangkat")
	}
}
