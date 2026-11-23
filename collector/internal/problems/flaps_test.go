package problems

import "testing"

func TestFlapConds(t *testing.T) {
	devs := []Device{{ID: 1, Name: "Radio Pit A", Status: "up"}, {ID: 2, Name: "Mati", Status: "down"}, {ID: 3, Name: "NVR", Status: "up", InMaintenance: "ganti kabel"}}
	list := []PortFlap{
		{DeviceID: 1, IfIndex: 2, Name: "wlan1", Trap: 6, Poll: 2},     // trap dipakai, bukan dijumlah
		{DeviceID: 1, IfIndex: 3, Name: "ether1", Poll: 3},             // belum cukup
		{DeviceID: 1, IfIndex: 4, Name: "ether2", Poll: 2, Open: true}, // sudah terbuka, masih berubah: bertahan
		{DeviceID: 1, IfIndex: 5, Name: "ether3", Poll: 1, Open: true}, // sudah tenang: tertutup
		{DeviceID: 2, IfIndex: 1, Name: "eth0", Poll: 9},               // perangkat down: tidak dinilai
		{DeviceID: 3, IfIndex: 1, Name: "eth0", Trap: 5},
	}
	cs := FlapConds(list, devs, 4, 15)
	if len(cs) != 3 {
		t.Fatalf("%d kondisi: %+v", len(cs), cs)
	}
	if cs[0].Title != "Radio Pit A: port wlan1 naik-turun 6 kali dalam 15 menit" || *cs[0].IfIndex != 2 || cs[0].Kind != "port_flap" {
		t.Fatalf("%+v", cs[0])
	}
	if *cs[1].IfIndex != 4 || cs[2].Suppressed != "maintenance" {
		t.Fatalf("%+v %+v", cs[1], cs[2])
	}
	if cs[0].Key() == cs[1].Key() {
		t.Fatal("kunci sama")
	}
}
