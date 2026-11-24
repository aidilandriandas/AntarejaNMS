package forecast

import (
	"testing"
	"time"
)

func TestFitDisk(t *testing.T) {
	now := time.Date(2026, 11, 24, 12, 0, 0, 0, time.UTC)
	var pts []Point
	for h := 72; h >= 0; h-- { // naik 2 poin per hari, sekarang 90%
		pts = append(pts, Point{now.Add(-time.Duration(h) * time.Hour), 90 - float64(h)/24*2})
	}
	r, ok := Fit(pts, Kinds[0], now)
	if !ok || r.ETA == nil {
		t.Fatal(ok, r)
	}
	if d := r.ETA.Sub(now).Hours() / 24; d < 4.9 || d > 5.1 { // (100-90)/2 = 5 hari
		t.Fatalf("ETA %.2f hari", d)
	}
	// turun: tidak menuju penuh
	for i := range pts {
		pts[i].V = 100 - pts[i].V
	}
	if r, _ := Fit(pts, Kinds[0], now); r.ETA != nil {
		t.Fatal("disk mengosong tidak punya ETA")
	}
	if _, ok := Fit(pts[:5], Kinds[0], now); ok {
		t.Fatal("data terlalu sedikit")
	}
}

func TestFitBahanBakarSetelahIsiUlang(t *testing.T) {
	now := time.Date(2026, 11, 24, 12, 0, 0, 0, time.UTC)
	var pts []Point
	// 30 jam lalu sampai 13 jam lalu: turun dari 40 ke 20; isi ulang ke 95; lalu turun 1 poin per jam
	for h := 30; h > 12; h-- {
		pts = append(pts, Point{now.Add(-time.Duration(h) * time.Hour), 20 + float64(h-13)*20/17})
	}
	for h := 12; h >= 0; h-- {
		pts = append(pts, Point{now.Add(-time.Duration(h) * time.Hour), 95 - float64(12-h)})
	}
	r, ok := Fit(pts, Kinds[1], now)
	if !ok || r.ETA == nil || r.Points != 13 {
		t.Fatal(ok, r)
	}
	if h := r.ETA.Sub(now).Hours(); h < 82 || h > 84 { // 83% tersisa, 1% per jam
		t.Fatalf("ETA %.1f jam", h)
	}
}
