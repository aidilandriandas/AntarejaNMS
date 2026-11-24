package problems

import (
	"strings"
	"testing"
)

func TestRoutingConds(t *testing.T) {
	as := int64(64512)
	stop, start := 1, 2
	devs := []Device{{ID: 1, Name: "core-ho", Status: "up"}, {ID: 2, Name: "core-pit", Status: "down"}}
	rows := []RoutingRow{
		{DeviceID: 1, Proto: "bgp", Addr: "10.0.0.2", RemoteAS: &as, State: 3, Admin: &start},
		{DeviceID: 1, Proto: "bgp", Addr: "10.0.0.3", State: 1, Admin: &stop}, // dimatikan admin
		{DeviceID: 1, Proto: "bgp", Addr: "10.0.0.4", State: 6, Missing: true},
		{DeviceID: 1, Proto: "ospf", Addr: "10.1.0.2", RouterID: "1.1.1.2", State: 3},
		{DeviceID: 1, Proto: "ospf", Addr: "10.1.0.3", State: 4}, // 2-way normal
		{DeviceID: 2, Proto: "ospf", Addr: "10.2.0.2", State: 1},
	}
	cs := RoutingConds(rows, devs)
	if len(cs) != 4 {
		t.Fatalf("%d %+v", len(cs), cs)
	}
	if !strings.Contains(cs[0].Title, "BGP ke 10.0.0.2 (AS 64512) active") || cs[0].Severity != "high" {
		t.Fatal(cs[0].Title)
	}
	if cs[1].Severity != "average" || !strings.Contains(cs[1].Title, "hilang") {
		t.Fatal(cs[1].Title)
	}
	if !strings.Contains(cs[2].Title, "OSPF 10.1.0.2 (router 1.1.1.2) init") {
		t.Fatal(cs[2].Title)
	}
	if cs[3].Suppressed != "parent" {
		t.Fatal("router down ditekan")
	}
}
