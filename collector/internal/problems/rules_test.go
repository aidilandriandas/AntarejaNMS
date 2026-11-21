package problems

import (
	"math"
	"testing"
)

var th = Thresholds{LatencyMS: 150, LossPct: 20, UtilPct: 90, SNMPFailCycles: 2}

func find(r Result, dev int64, kind string) *Cond {
	for i := range r.Conds {
		if r.Conds[i].DeviceID == dev && r.Conds[i].Kind == kind {
			return &r.Conds[i]
		}
	}
	return nil
}

func TestDownSeverityAndParent(t *testing.T) {
	devs := []Device{
		{ID: 1, Name: "Core", Category: "core", Status: "down", Reason: "tidak menjawab ping"},
		{ID: 2, Name: "Radio", Category: "ptp", Status: "down", ParentID: 1},
		{ID: 3, Name: "AP", Category: "wireless", Status: "down", ParentID: 2},
		{ID: 4, Name: "NVR", Category: "nvr", Status: "down"},
	}
	r := Evaluate(devs, th)
	if c := find(r, 1, "down"); c == nil || c.Severity != "disaster" || c.Suppressed != "" {
		t.Fatalf("core: %+v", c)
	}
	if c := find(r, 2, "down"); c == nil || c.Suppressed != "parent" || c.SupNote != "terdampak: induk Core down" {
		t.Fatalf("radio: %+v", c)
	}
	if c := find(r, 3, "down"); c == nil || c.Suppressed != "parent" || c.SupNote != "terdampak: induk Radio down" {
		t.Fatalf("AP (cucu): %+v", c)
	}
	if c := find(r, 4, "down"); c == nil || c.Severity != "high" || c.Suppressed != "" {
		t.Fatalf("nvr: %+v", c)
	}
}

func TestParentLoopSafe(t *testing.T) {
	devs := []Device{{ID: 1, Name: "A", Status: "down", ParentID: 2}, {ID: 2, Name: "B", Status: "up", ParentID: 1, PingEnabled: true, PingKnown: true}}
	r := Evaluate(devs, th)
	if c := find(r, 1, "down"); c == nil || c.Suppressed != "" {
		t.Fatalf("%+v", c)
	}
}

func TestLatencyLossUnknown(t *testing.T) {
	devs := []Device{
		{ID: 1, Name: "A", Status: "up", PingEnabled: true, PingKnown: true, RTTAvg: 210, LossAvg: 60},
		{ID: 2, Name: "B", Status: "up", PingEnabled: true, PingKnown: false},
		{ID: 3, Name: "C", Status: "up", PingEnabled: true, PingKnown: true, RTTAvg: math.NaN(), LossAvg: 10},
	}
	r := Evaluate(devs, th)
	if c := find(r, 1, "latency"); c == nil || *c.Value != 210 {
		t.Fatal("latency A")
	}
	if c := find(r, 1, "loss"); c == nil || c.Severity != "high" {
		t.Fatal("loss A")
	}
	if !r.Unknown["2/latency/-1"] || find(r, 2, "latency") != nil {
		t.Fatal("B harus unknown")
	}
	// sedang menuju down (ping terakhir gagal total): loss tidak dinilai
	r2 := Evaluate([]Device{{ID: 9, Name: "X", Status: "up", PingEnabled: true, PingKnown: true, FailStreak: 1, RTTAvg: 1, LossAvg: 40}}, th)
	if find(r2, 9, "loss") != nil || !r2.Unknown["9/loss/-1"] {
		t.Fatal("loss saat menuju down harus unknown")
	}
	// tepat di ambang (1 dari 5 putaran hilang = 20%) belum problem
	r3 := Evaluate([]Device{{ID: 9, Name: "X", Status: "up", PingEnabled: true, PingKnown: true, RTTAvg: 1, LossAvg: 20}}, th)
	if find(r3, 9, "loss") != nil {
		t.Fatal("loss 20% tepat ambang bukan problem")
	}
	if find(r, 3, "latency") != nil || find(r, 3, "loss") != nil {
		t.Fatal("C normal")
	}
}

func TestSNMPHysteresis(t *testing.T) {
	r := Evaluate([]Device{{ID: 1, Name: "A", Status: "up", SNMPEnabled: true, SNMPKnown: true, SNMPFails: 1}}, th)
	if find(r, 1, "snmp") != nil || !r.Unknown["1/snmp/-1"] {
		t.Fatal("1 kali gagal (< ambang) harus unknown, bukan selesai")
	}
	r = Evaluate([]Device{{ID: 1, Name: "A", Status: "up", SNMPEnabled: true, SNMPKnown: true, SNMPFails: 0}}, th)
	if r.Unknown["1/snmp/-1"] {
		t.Fatal("berhasil lagi harus menutup problem")
	}
}

func TestPortsSNMPMaintenance(t *testing.T) {
	devs := []Device{{ID: 1, Name: "SW", Status: "up", SNMPEnabled: true, SNMPKnown: true, SNMPFails: 2, InMaintenance: "Ganti UPS",
		Ports: []Port{{Index: 5, Name: "Gi0/1", AdminUp: true, OperUp: false}, {Index: 6, Name: "Gi0/2", AdminUp: true, OperUp: true, UtilKnown: true, Util: 95},
			{Index: 7, Name: "Gi0/3", AdminUp: false, OperUp: false}, {Index: 8, Name: "Gi0/4", AdminUp: true, OperUp: true}}}}
	r := Evaluate(devs, th)
	if len(r.Conds) != 3 {
		t.Fatalf("ingin 3 kondisi (snmp, port_down, port_util), dapat %d: %+v", len(r.Conds), r.Conds)
	}
	for _, c := range r.Conds {
		if c.Suppressed != "maintenance" {
			t.Fatalf("harus ditekan maintenance: %+v", c)
		}
	}
	if !r.Unknown["1/port_util/8"] {
		t.Fatal("util port 8 belum diketahui")
	}
}
