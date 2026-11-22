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

func TestSensors(t *testing.T) {
	v := -81.0
	devs := []Device{{ID: 1, Name: "Radio A", Status: "up", Sensors: []Sensor{
		{Key: "wl:signal:1", Label: "Sinyal (PTP)", Unit: "dBm", Status: "crit", Value: &v, Fresh: true},
		{Key: "ups:source", Label: "Sumber daya", Text: "BATERAI", Status: "warn", Fresh: true},
		{Key: "temp:board", Label: "Suhu", Status: "crit", Fresh: false},
		{Key: "cpu", Label: "CPU", Status: "ok", Fresh: true},
	}}}
	r := Evaluate(devs, th)
	var got []string
	for _, c := range r.Conds {
		got = append(got, c.Key()+"="+c.Severity+":"+c.Title)
	}
	want := []string{"1/sensor/wl:signal:1=high:Radio A: Sinyal (PTP) -81 dBm", "1/sensor/ups:source=warning:Radio A: Sumber daya BATERAI"}
	if len(got) != 2 || got[0] != want[0] || got[1] != want[1] {
		t.Fatalf("%q", got)
	}
	if !r.Unknown["1/sensor/temp:board"] {
		t.Fatal("sensor basi harus unknown")
	}
}

func TestIPConflict(t *testing.T) {
	th2 := th
	th2.ConflictChanges, th2.ConflictHours = 3, 6
	devs := []Device{{ID: 1, Name: "Core", Status: "up", Conflicts: []Conflict{
		{IP: "10.10.5.20", MACs: []string{"aa:aa:aa:aa:aa:01", "bb:bb:bb:bb:bb:02"}, Changes: 4}, // konflik nyata
		{IP: "10.10.5.21", MACs: []string{"aa:aa:aa:aa:aa:03", "bb:bb:bb:bb:bb:04"}, Changes: 1}, // DHCP ganti sekali
		{IP: "10.10.5.22", MACs: []string{"a", "b", "c"}, Changes: 5},                            // >2 MAC: pergantian perangkat berurutan, bukan konflik
	}}}
	r := Evaluate(devs, th2)
	if len(r.Conds) != 1 || r.Conds[0].Key() != "1/sensor/ip:10.10.5.20" || r.Conds[0].Kind != "ip_conflict" {
		t.Fatalf("%+v", r.Conds)
	}
}

func TestCameraHealth(t *testing.T) {
	devs := []Device{{ID: 4, Name: "NVR", Status: "up", Cameras: []CamHealth{
		{ID: 1, Name: "CAM 01", Health: "covered", Detail: "gambar polos", Fresh: true},
		{ID: 2, Name: "CAM 02", Health: "nosignal", Detail: "login RTSP ditolak", Fresh: true},
		{ID: 3, Name: "CAM 03", Health: "ok", Fresh: true},
		{ID: 4, Name: "CAM 04", Health: "dark", Fresh: false},
	}}}
	r := Evaluate(devs, th)
	if len(r.Conds) != 2 || r.Conds[1].Severity != "average" || r.Conds[0].Title != "Kamera CAM 01: gambar polos" {
		t.Fatalf("%+v", r.Conds)
	}
	if !r.Unknown["4/sensor/cam:4"] {
		t.Fatal("hasil basi harus unknown")
	}
}

func TestDeviceOverride(t *testing.T) {
	devs := []Device{
		{ID: 1, Name: "Radio jauh", Status: "up", PingEnabled: true, PingKnown: true, RTTAvg: 200, LatencyMS: 300},
		{ID: 2, Name: "Switch", Status: "up", PingEnabled: true, PingKnown: true, RTTAvg: 200},
	}
	r := Evaluate(devs, th)
	if find(r, 1, "latency") != nil || find(r, 2, "latency") == nil {
		t.Fatalf("%+v", r.Conds)
	}
}
