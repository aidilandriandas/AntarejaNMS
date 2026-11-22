package incidents

import (
	"testing"
	"time"
)

func TestDecide(t *testing.T) {
	now := time.Now()
	c := cfg{GroupMin: 3, Window: 10 * time.Minute}
	high := func(ago time.Duration, sup string) Cand {
		return Cand{Severity: "high", Started: now.Add(-ago), Suppressed: sup}
	}
	cases := []struct {
		name string
		in   []Cand
		want bool
	}{
		{"satu disaster", []Cand{{Severity: "disaster", Started: now}}, true},
		{"disaster ditekan maintenance", []Cand{{Severity: "disaster", Started: now, Suppressed: "maintenance"}}, false},
		{"dua high", []Cand{high(time.Minute, ""), high(2*time.Minute, "")}, false},
		{"tiga high baru", []Cand{high(3*time.Minute, ""), high(2*time.Minute, ""), high(time.Minute, "")}, true},
		{"tiga high tapi satu lama", []Cand{high(30*time.Minute, ""), high(2*time.Minute, ""), high(time.Minute, "")}, false},
		{"anak ditekan induk tidak dihitung", []Cand{high(time.Minute, ""), high(time.Minute, "parent"), high(time.Minute, "parent")}, false},
	}
	for _, tc := range cases {
		if got := decide(tc.in, c); got != tc.want {
			t.Errorf("%s: dapat %v, harap %v", tc.name, got, tc.want)
		}
	}
}

func TestTitle(t *testing.T) {
	one := []Cand{{DeviceID: 1, Title: "Core Pit A down", SiteName: "PIT-A"}}
	if got := Title(one); got != "Core Pit A down" {
		t.Errorf("satu: %q", got)
	}
	many := []Cand{{DeviceID: 1, SiteName: "PIT-A"}, {DeviceID: 2, SiteName: "PIT-A"}, {DeviceID: 2, SiteName: "PIT-A"}, {DeviceID: 3, Suppressed: "maintenance"}}
	if got := Title(many); got != "Gangguan site PIT-A: 2 perangkat terdampak" {
		t.Errorf("banyak: %q", got)
	}
	if got := maxSev([]Cand{{Severity: "high"}, {Severity: "disaster"}, {Severity: "average"}}); got != "disaster" {
		t.Errorf("maxSev: %q", got)
	}
}

func TestClusterMatch(t *testing.T) {
	t0 := time.Date(2026, 11, 1, 8, 0, 0, 0, time.UTC)
	at := func(m int) Cand { return Cand{Started: t0.Add(time.Duration(m) * time.Minute)} }
	cl := cluster([]Cand{at(0), at(5), at(50), at(3000), at(3010)}, time.Hour)
	if len(cl) != 2 || len(cl[0]) != 3 || len(cl[1]) != 2 {
		t.Fatalf("cluster: %v", cl)
	}
	if len(cluster(nil, time.Hour)) != 0 {
		t.Error("cluster kosong")
	}
	incs := []Inc{{ID: 7, Opened: t0, Last: t0.Add(30 * time.Minute)}}
	if match(incs, at(80), time.Hour) != 0 || match(incs, at(-50), time.Hour) != 0 {
		t.Error("harusnya cocok")
	}
	if match(incs, at(100), time.Hour) != -1 || match(incs, at(-70), time.Hour) != -1 {
		t.Error("harusnya tidak cocok")
	}
}
