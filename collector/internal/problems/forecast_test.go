package problems

import (
	"strings"
	"testing"
	"time"
)

func TestForecastConds(t *testing.T) {
	now := time.Now()
	good, bad := 0.9, 0.2
	devs := []Device{{ID: 1, Name: "srv-fms"}, {ID: 2, Name: "genset-pit-b"}}
	list := []Forecast{
		{DeviceID: 1, Key: "px:disk:root", Class: "disk", Label: "Disk /", Current: 92, PerDay: 2, R2: &good, ETA: now.Add(36 * time.Hour)},
		{DeviceID: 1, Key: "px:disk:data", Class: "disk", Label: "Disk /data", Current: 92, PerDay: 2, R2: &bad, ETA: now.Add(36 * time.Hour)},
		{DeviceID: 1, Key: "px:disk:var", Class: "disk", Label: "Disk /var", Current: 50, PerDay: 1, R2: &good, ETA: now.Add(20 * 24 * time.Hour)},
		{DeviceID: 2, Key: "mb:7", Class: "fuel", Label: "Solar", Current: 12, PerDay: -48, ETA: now.Add(5 * time.Hour)},
	}
	cs := ForecastConds(list, devs, now)
	if len(cs) != 2 {
		t.Fatalf("%+v", cs)
	}
	if cs[0].Severity != "average" || !strings.Contains(cs[0].Title, "penuh dalam 35 jam") && !strings.Contains(cs[0].Title, "penuh dalam 36 jam") {
		t.Fatalf("%+v", cs[0])
	}
	if cs[1].Severity != "high" || !strings.Contains(cs[1].Title, "habis") || !strings.Contains(cs[1].Detail, "turun 2.0 per jam") {
		t.Fatalf("%+v", cs[1])
	}
}
