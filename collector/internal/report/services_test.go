package report

import (
	"math"
	"strings"
	"testing"
)

func TestIntervals(t *testing.T) {
	u := union([]iv{{5, 8}, {1, 3}, {2, 4}, {8, 9}, {10, 10}})
	if len(u) != 2 || u[0] != (iv{1, 4}) || u[1] != (iv{5, 9}) {
		t.Fatalf("union %v", u)
	}
	s := subtract([]iv{{0, 10}}, []iv{{2, 3}, {5, 7}})
	if len(s) != 3 || length(s) != 7 {
		t.Fatalf("subtract %v", s)
	}
	// redundan 2 perangkat min 1 hidup: down hanya saat keduanya down
	b := belowMin([][]iv{{{0, 10}}, {{5, 15}}}, 2, 1)
	if len(b) != 1 || b[0] != (iv{5, 10}) {
		t.Fatalf("belowMin %v", b)
	}
	// seri 2 perangkat min 2: down bila salah satu down
	b = belowMin([][]iv{{{0, 10}}, {{5, 15}}}, 2, 2)
	if len(b) != 1 || b[0] != (iv{0, 15}) {
		t.Fatalf("belowMin seri %v", b)
	}
}

func TestComputeServices(t *testing.T) {
	defs := []svcDef{
		{name: "CCTV Pit A", target: 99, comps: []svcComp{{minUp: 1, devs: []int64{1}}, {minUp: 1, devs: []int64{2, 3}}}},
		{name: "Baru", target: 99.5, first: 900, comps: []svcComp{{minUp: 1, devs: []int64{4}}}},
	}
	down := map[int64][]iv{1: {{100, 160}}, 2: {{200, 400}}, 3: {{300, 350}}}
	r := computeServices(defs, down, 0, 1000)
	if r[0].Name != "CCTV Pit A" {
		t.Fatalf("urutan %v", r)
	}
	// down = 60 (uplink) + 50 (dua radio sama-sama down) = 110 dari 1000
	if r[0].Down != 110 || r[0].Outages != 2 || math.Abs(r[0].Avail-89) > 1e-9 || r[0].Longest != 60 || r[0].Degraded != 150 {
		t.Fatalf("%+v", r[0])
	}
	if r[1].Avail != 100 || r[1].Down != 0 {
		t.Fatalf("%+v", r[1])
	}
}

func TestRenderServices(t *testing.T) {
	d := Data{Target: 99.5, Services: []SvcRow{{Name: "CCTV Pit A", Target: 99, Avail: 97.5, Down: 3600, Outages: 2}, {Name: "Baru", Target: 99.5, Avail: -1}}}
	h, err := HTML("monthly", d)
	if err != nil || !strings.Contains(h, "CCTV Pit A") || !strings.Contains(h, "Layanan") {
		t.Fatalf("html err=%v", err)
	}
	if tx := Text("monthly", d); !strings.Contains(tx, "CCTV Pit A: 97,5") {
		t.Fatalf("teks:\n%s", tx)
	}
	if tg := Telegram("monthly", d); !strings.Contains(tg, "CCTV Pit A") {
		t.Fatal("telegram tanpa layanan")
	}
}
