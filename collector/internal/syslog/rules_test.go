package syslog

import (
	"testing"
	"time"
)

func TestRuleMatchDanJendela(t *testing.T) {
	site := int64(3)
	r := &Rule{ID: 1, Pattern: `login failure from \d+\.\d+`, Regex: true, MaxSeverity: 5, MinCount: 3, Window: time.Minute}
	if err := r.Compile(); err != nil {
		t.Fatal(err)
	}
	m := Message{Severity: 4, Text: "system,error,critical LOGIN FAILURE from 10.1.2.3 via ssh"}
	if !r.Match(m, 9, &site) {
		t.Fatal("regex tanpa beda huruf besar kecil")
	}
	if r.Match(Message{Severity: 6, Text: m.Text}, 9, &site) {
		t.Fatal("tingkat info di atas batas")
	}
	r.SiteID = &[]int64{4}[0]
	if r.Match(m, 9, &site) {
		t.Fatal("site lain")
	}
	r.SiteID = nil

	var c Counter
	t0 := time.Date(2026, 11, 24, 10, 0, 0, 0, time.UTC)
	steps := []struct {
		at   time.Duration
		fire bool
	}{{0, false}, {20 * time.Second, false}, {70 * time.Second, false}, // yang pertama keluar jendela
		{75 * time.Second, true}, {80 * time.Second, false}, // terpicu; tulis ulang dibatasi 10 detik
		{100 * time.Second, true}}
	for i, s := range steps {
		if f, _ := c.Add(r, 9, t0.Add(s.at)); f != s.fire {
			t.Fatalf("langkah %d: %v", i, f)
		}
	}
	bad := &Rule{Pattern: "(", Regex: true}
	if bad.Compile() == nil {
		t.Fatal("regex rusak harus galat")
	}
	txt := &Rule{Pattern: "Link Down", MaxSeverity: 7, MinCount: 1, Window: time.Minute}
	_ = txt.Compile()
	if !txt.Match(Message{Severity: 6, Text: "interface ether1 link down"}, 1, nil) {
		t.Fatal("teks biasa")
	}
}
