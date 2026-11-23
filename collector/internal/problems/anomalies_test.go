package problems

import "testing"

func TestAnomalyConds(t *testing.T) {
	idx := 3
	devs := []Device{{ID: 1, Name: "core", Status: "down"}, {ID: 2, Name: "sw-pit", ParentID: 1}, {ID: 3, Name: "nvr", InMaintenance: "ganti disk"}, {ID: 4, Name: "cam"}}
	list := []OpenAnomaly{
		{ID: 10, DeviceID: 4, Metric: "port_in", IfIndex: &idx, Message: "Trafik masuk eth0 anjlok", Value: 1},
		{ID: 11, DeviceID: 2, Metric: "latency", Message: "Latensi naik", Value: 80},
		{ID: 12, DeviceID: 3, Metric: "latency", Message: "Latensi naik", Value: 90},
		{ID: 13, DeviceID: 99, Metric: "latency", Message: "x"},
	}
	cs := AnomalyConds(list, devs, "warning")
	if len(cs) != 3 {
		t.Fatalf("%d kondisi", len(cs))
	}
	if cs[0].Sensor != "anm:port_in:if3" || cs[0].Kind != "anomaly" || cs[0].Severity != "warning" || cs[0].Title != "cam: Trafik masuk eth0 anjlok" || cs[0].Suppressed != "" {
		t.Fatalf("%+v", cs[0])
	}
	if cs[1].Suppressed != "parent" || cs[2].Suppressed != "maintenance" {
		t.Fatalf("penekanan salah: %q %q", cs[1].Suppressed, cs[2].Suppressed)
	}
	if cs[0].Key() == cs[1].Key() {
		t.Fatal("kunci sama")
	}
}
