package notify

import (
	"context"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestImpactLine(t *testing.T) {
	db := dbtest.Open(t)
	ctx := context.Background()
	site := dbtest.ID(t, db, `INSERT INTO sites (code, name) VALUES ('PIT-X', 'Pit X') RETURNING id`)
	other := dbtest.ID(t, db, `INSERT INTO sites (code, name) VALUES ('KTR', 'Kantor') RETURNING id`)
	core := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id) VALUES ('core', '10.0.0.1', $1) RETURNING id`, other)
	ptp := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id, parent_id) VALUES ('ptp', '10.0.0.2', $1, $2) RETURNING id`, site, core)
	sw := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id, parent_id) VALUES ('sw', '10.0.0.3', $1, $2) RETURNING id`, site, ptp)
	nvr := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id, parent_id) VALUES ('nvr', '10.0.0.4', $1, $2) RETURNING id`, site, sw)
	dbtest.Exec(t, db, `INSERT INTO devices (name, host, parent_id, enabled) VALUES ('mati', '10.0.0.9', $1, false)`, sw) // nonaktif: tidak dihitung
	dbtest.Exec(t, db, `INSERT INTO cameras (name, device_id, site_id, host) VALUES ('cam 1', $1, $2, '10.0.1.1'), ('cam 2', $1, $2, '10.0.1.2')`, nvr, site)
	// Layanan CCTV Pit X: butuh nvr. Layanan Internet: dua uplink, minimal 1 hidup (core + backup).
	backup := dbtest.ID(t, db, `INSERT INTO devices (name, host, site_id) VALUES ('backup', '10.0.0.5', $1) RETURNING id`, other)
	svc := dbtest.ID(t, db, `INSERT INTO services (name) VALUES ('CCTV Pit X') RETURNING id`)
	comp := dbtest.ID(t, db, `INSERT INTO service_components (service_id, name, min_up) VALUES ($1, 'NVR', 1) RETURNING id`, svc)
	dbtest.Exec(t, db, `INSERT INTO service_component_devices (component_id, device_id) VALUES ($1, $2)`, comp, nvr)
	inet := dbtest.ID(t, db, `INSERT INTO services (name) VALUES ('Internet') RETURNING id`)
	ic := dbtest.ID(t, db, `INSERT INTO service_components (service_id, name, min_up) VALUES ($1, 'Uplink', 1) RETURNING id`, inet)
	dbtest.Exec(t, db, `INSERT INTO service_component_devices (component_id, device_id) VALUES ($1, $2), ($1, $3)`, ic, core, backup)

	if got, want := ImpactLine(ctx, db, ptp), "2 perangkat, 2 kamera, 1 layanan terganggu, 1 site putus total"; got != want {
		t.Fatalf("ptp: %q, mau %q", got, want)
	}
	if got, want := ImpactLine(ctx, db, core), "3 perangkat, 2 kamera, 1 layanan terganggu, 1 layanan tanpa cadangan, 1 site putus total"; got != want {
		t.Fatalf("core: %q, mau %q", got, want)
	}
	if got := ImpactLine(ctx, db, backup); got != "1 layanan tanpa cadangan" {
		t.Fatalf("backup: %q", got)
	}
	// rantai melingkar tidak membuat macet
	dbtest.Exec(t, db, `UPDATE devices SET parent_id = $1 WHERE id = $2`, nvr, core)
	if got := ImpactLine(ctx, db, core); got == "" {
		t.Fatal("rantai melingkar tetap dihitung")
	}
}
