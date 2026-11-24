package notify

import (
	"strings"
	"testing"
	"time"
)

func TestRenderTemplat(t *testing.T) {
	vals := map[string]string{"judul": "Radio <A> down", "site": "PIT-A", "dampak": ""}
	tpl := "{judul} di {site}\nDampak: {dampak}\nTidak dikenal: {apa}\n<b>tetap teks</b>"
	got := Render(tpl, vals, esc)
	want := "Radio &lt;A&gt; down di PIT-A\nTidak dikenal: {apa}\n&lt;b&gt;tetap teks&lt;/b&gt;"
	if got != want {
		t.Fatalf("%q", got)
	}
}

func TestTemplatedKanal(t *testing.T) {
	p := Problem{Severity: "high", Title: "Core down", Device: "Core", Host: "10.0.0.1", Site: "HO", StartedAt: time.Now().Add(-90 * time.Minute)}
	c := Channel{TemplateOpen: "{severity} {judul}\n{perangkat} ({host}) {durasi}\n{eskalasi}"}
	m := Templated(c, "open", p, 15, "https://nms")
	if m.Subject != "[ANTAREJA] HIGH Core down" || !strings.Contains(m.Text, "Core (10.0.0.1) 1 jam 30 menit") || !strings.Contains(m.Text, "belum di-ACK 15 menit") {
		t.Fatalf("%+v", m)
	}
	// templat pulih kosong: format bawaan
	if r := Templated(c, "resolve", p, 0, ""); !strings.HasPrefix(r.Subject, "[ANTAREJA] PULIH") {
		t.Fatal(r.Subject)
	}
}
