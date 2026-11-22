package problems

import (
	"testing"
	"time"
)

func f(v float64) *float64 { return &v }

func TestTriggerDurasiDanJadwal(t *testing.T) {
	t0 := time.Date(2026, 11, 23, 8, 0, 0, 0, time.Local) // Senin 08:00
	start, end := 7*60, 18*60
	tr := Trigger{ID: 4, Name: "Uplink mati di jam kerja", Severity: "high", Metric: "port_in", Match: "sfp", Op: "<", Threshold: 0.01,
		Duration: 10 * time.Minute, Scope: "all", Days: 0b0111111, StartMin: &start, EndMin: &end}
	devs := []Device{{ID: 1, Name: "Core", Status: "up"}}
	ports := map[int64][]PortVal{1: {{Index: 3, Name: "sfp1", InBps: f(0), Fresh: true}, {Index: 4, Name: "ether2", InBps: f(0), Fresh: true}}}
	st := TriggerState{}

	r := EvaluateTriggers([]Trigger{tr}, devs, ports, t0, st)
	if len(r.Conds) != 0 || !r.Unknown[SensorKey(1, "trg:4:if3")] {
		t.Fatalf("belum 10 menit: conds=%v unknown=%v", r.Conds, r.Unknown)
	}
	r = EvaluateTriggers([]Trigger{tr}, devs, ports, t0.Add(11*time.Minute), st)
	if len(r.Conds) != 1 || r.Conds[0].Sensor != "trg:4:if3" || *r.Conds[0].IfIndex != 3 || r.Conds[0].Kind != "custom" {
		t.Fatalf("harus 1 problem di sfp1 (ether2 tidak cocok saringan): %+v", r.Conds)
	}
	// trafik kembali: kondisi hilang, state dibuang
	ports[1][0].InBps = f(5e6)
	r = EvaluateTriggers([]Trigger{tr}, devs, ports, t0.Add(12*time.Minute), st)
	if len(r.Conds) != 0 || len(st) != 0 {
		t.Fatalf("pulih: %v state=%v", r.Conds, st)
	}
	// di luar jam kerja tidak pernah aktif
	ports[1][0].InBps = f(0)
	night := time.Date(2026, 11, 23, 20, 0, 0, 0, time.Local)
	EvaluateTriggers([]Trigger{tr}, devs, ports, night, st)
	r = EvaluateTriggers([]Trigger{tr}, devs, ports, night.Add(time.Hour), st)
	if len(r.Conds) != 0 {
		t.Fatal("di luar jadwal tidak boleh ada problem")
	}
	sunday := time.Date(2026, 11, 29, 9, 0, 0, 0, time.Local)
	if tr.Active(sunday) {
		t.Fatal("Minggu tidak termasuk hari aktif")
	}
	over := Trigger{Days: 127, StartMin: &end, EndMin: &start} // 18:00 sampai 07:00
	if !over.Active(night) || over.Active(t0.Add(2*time.Hour)) {
		t.Fatal("jadwal melewati tengah malam salah")
	}
}

func TestTriggerSensorLingkupDanTekan(t *testing.T) {
	now := time.Now()
	tr := Trigger{ID: 9, Name: "Suhu panel", Severity: "average", Metric: "sensor", SensorClass: "temperature", Op: ">=", Threshold: 60,
		Scope: "site", SiteID: 2, Days: 127}
	devs := []Device{
		{ID: 1, Name: "Switch A", SiteID: 2, Status: "up", Sensors: []Sensor{{Key: "temp.cpu", Label: "Suhu CPU", Unit: "°C", Class: "temperature", Value: f(71), Fresh: true}}},
		{ID: 2, Name: "Switch B", SiteID: 3, Status: "up", Sensors: []Sensor{{Key: "temp.cpu", Label: "Suhu CPU", Class: "temperature", Value: f(90), Fresh: true}}},
		{ID: 3, Name: "Switch C", SiteID: 2, Status: "up", InMaintenance: "Ganti kipas", Sensors: []Sensor{{Key: "t", Label: "Suhu", Class: "temperature", Value: f(80), Fresh: true}}},
		{ID: 4, Name: "Switch D", SiteID: 2, Status: "down", Sensors: []Sensor{{Key: "t", Label: "Suhu", Class: "temperature", Value: f(80), Fresh: true}}},
	}
	r := EvaluateTriggers([]Trigger{tr}, devs, nil, now, TriggerState{})
	if len(r.Conds) != 2 {
		t.Fatalf("site 2 saja, perangkat up: %+v", r.Conds)
	}
	if r.Conds[0].DeviceID != 1 || r.Conds[0].Suppressed != "" || r.Conds[1].Suppressed != "maintenance" {
		t.Fatalf("tekan maintenance: %+v", r.Conds)
	}
	if !r.Unknown[SensorKey(4, "trg:9")] {
		t.Fatal("perangkat down: problem trigger dibiarkan")
	}
	if r.Conds[0].Detail != "trigger: Suhu CPU >= 60 °C, langsung" {
		t.Fatalf("detail: %q", r.Conds[0].Detail)
	}
	if num(0.0021) != "0,002" || num(12.5) != "12,5" || num(0) != "0" {
		t.Fatalf("num: %s %s %s", num(0.0021), num(12.5), num(0))
	}
	if r.Conds[0].Title != "Switch A: Suhu panel (71 °C)" {
		t.Fatalf("judul: %q", r.Conds[0].Title)
	}
}
