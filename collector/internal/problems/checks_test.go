package problems

import (
	"testing"
	"time"
)

func TestCheckConds(t *testing.T) {
	now := time.Now()
	ms, slow, warn := 900.0, 500, 14
	soon, past := now.Add(5*24*time.Hour), now.Add(-time.Hour)
	devs := []Device{{ID: 1, Name: "srv-fms", Status: "up"}, {ID: 2, Name: "srv-dns", Status: "up", InMaintenance: "ganti disk"},
		{ID: 3, Name: "core", Status: "down"}, {ID: 4, Name: "srv-cctv", Status: "up", ParentID: 3}}
	list := []Check{
		{ID: 10, DeviceID: 1, Name: "Web FMS", Severity: "high", Status: "down", LastError: "kode HTTP 503"},
		{ID: 11, DeviceID: 1, Name: "API FMS", Severity: "high", Status: "slow", LastMS: &ms, SlowMS: &slow},
		{ID: 12, DeviceID: 2, Name: "DNS", Severity: "average", Status: "down"},
		{ID: 13, DeviceID: 4, Name: "VMS", Severity: "high", Status: "down"},
		{ID: 14, DeviceID: 1, Name: "Portal", Severity: "high", Status: "up", CertExp: &soon, CertWarn: &warn},
		{ID: 15, DeviceID: 1, Name: "Lama", Severity: "high", Status: "up", CertExp: &past, CertWarn: &warn},
		{ID: 16, DeviceID: 99, Name: "Hilang", Severity: "high", Status: "down"},
	}
	got := map[string]Cond{}
	for _, c := range CheckConds(list, devs, now) {
		got[c.Sensor] = c
	}
	if len(got) != 6 {
		t.Fatalf("6 kondisi (perangkat nonaktif dilewati), dapat %d: %v", len(got), got)
	}
	if c := got["chk:10"]; c.Severity != "high" || c.Suppressed != "" || c.Detail != "kode HTTP 503" || c.Kind != "check" {
		t.Errorf("cek down: %+v", c)
	}
	if c := got["chk:11"]; c.Severity != "warning" || c.Detail != "900 ms, ambang 500 ms" {
		t.Errorf("cek lambat: %+v", c)
	}
	if got["chk:12"].Suppressed != "maintenance" || got["chk:13"].Suppressed != "parent" {
		t.Errorf("maintenance dan induk down menekan notifikasi: %+v %+v", got["chk:12"], got["chk:13"])
	}
	if c := got["chk:14:cert"]; c.Severity != "warning" {
		t.Errorf("sertifikat 5 hari lagi: %+v", c)
	}
	if c := got["chk:15:cert"]; c.Severity != "average" {
		t.Errorf("sertifikat sudah habis: %+v", c)
	}
}
