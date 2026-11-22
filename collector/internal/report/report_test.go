package report

import (
	"strings"
	"testing"
	"time"
)

func TestLastFull(t *testing.T) {
	loc := time.FixedZone("WIB", 7*3600)
	now := time.Date(2026, 11, 22, 6, 30, 0, 0, loc) // Minggu
	cases := []struct {
		freq, key, label string
		start, end       string
		sendAt           string
	}{
		{"daily", "2026-11-21", "Sabtu, 21/11/2026", "2026-11-21", "2026-11-22", "2026-11-22 07:00"},
		{"weekly", "2026-11-09", "09/11 sampai 15/11/2026", "2026-11-09", "2026-11-16", "2026-11-16 07:00"},
		{"monthly", "2026-10", "Oktober 2026", "2026-10-01", "2026-11-01", "2026-11-01 07:00"},
	}
	for _, c := range cases {
		p, at, err := LastFull(c.freq, now, 7)
		if err != nil {
			t.Fatal(err)
		}
		if p.Key != c.key || p.Label != c.label || p.Start.Format("2006-01-02") != c.start || p.End.Format("2006-01-02") != c.end || at.Format("2006-01-02 15:04") != c.sendAt {
			t.Errorf("%s: %+v kirim %v", c.freq, p, at)
		}
	}
	// Januari → Desember tahun lalu
	p, _, _ := LastFull("monthly", time.Date(2027, 1, 3, 9, 0, 0, 0, loc), 7)
	if p.Key != "2026-12" || p.Label != "Desember 2026" {
		t.Errorf("pergantian tahun: %+v", p)
	}
	if _, _, err := LastFull("hourly", now, 7); err == nil {
		t.Error("frekuensi salah seharusnya galat")
	}
}

func TestRender(t *testing.T) {
	d := Data{
		Period: Period{Label: "Sabtu, 21/11/2026", Start: time.Date(2026, 11, 21, 0, 0, 0, 0, time.Local)}, Target: 99.5,
		Total: SiteAvail{Devices: 12, Down: 5400, Incidents: 3, Avail: 99.479}, BelowCount: 1,
		Sites:    []SiteAvail{{Site: "PIT-A", Devices: 7, Avail: 98.9, Down: 5400, Incidents: 3}, {Site: "JETTY", Devices: 5, Avail: 100}},
		Worst:    []DeviceAvail{{Name: "Radio <PTP> Pit A", Host: "10.0.0.5", Site: "PIT-A", Down: 5400, Incidents: 3, Avail: 93.75}},
		Problems: map[string]int{"high": 2, "warning": 4}, ProblemsN: 6, Generated: time.Now(), BaseURL: "https://nms.local",
	}
	h, err := HTML("daily", d)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"99,47%", "Radio &lt;PTP&gt; Pit A", "1 j 30 m", "High 2</span>, <span style=\"color:#b45309\">Warning 4", "/laporan/sla?bulan=2026-11", "PIT-A"} {
		if !strings.Contains(h, want) {
			t.Errorf("HTML tidak memuat %q", want)
		}
	}
	if strings.Contains(h, "<PTP>") {
		t.Error("nama perangkat tidak di-escape")
	}
	if s := Subject("daily", d); !strings.Contains(s, "harian") || !strings.Contains(s, "99,47%") {
		t.Errorf("subjek: %s", s)
	}
	if tx := Text("daily", d); !strings.Contains(tx, "Radio <PTP> Pit A") || !strings.Contains(tx, "Per site") {
		t.Errorf("teks: %s", tx)
	}
	if tg := Telegram("daily", d); !strings.Contains(tg, "Radio &lt;PTP&gt;") {
		t.Errorf("telegram tidak di-escape: %s", tg)
	}
	if pct(100) != "100,00%" || pct(99.999) != "99,99%" || pct(-1) != "tidak ada" {
		t.Errorf("pct: %s %s %s", pct(100), pct(99.999), pct(-1))
	}
}
