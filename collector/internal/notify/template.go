package notify

import (
	"fmt"
	"strings"
	"time"
)

// Penanda templat pesan. Nilainya di-escape untuk Telegram (HTML); teks templat sendiri juga di-escape,
// jadi templat tidak bisa menyisipkan markup.
var Placeholders = []string{"judul", "severity", "perangkat", "host", "site", "detail", "mulai", "durasi", "dampak", "runbook", "tautan", "eskalasi", "ack_oleh"}

func templateValues(kind string, p Problem, delay int, base string) map[string]string {
	v := map[string]string{
		"judul": p.Title, "severity": sevLabel[p.Severity], "perangkat": p.Device, "host": p.Host, "site": p.Site,
		"detail": p.Detail, "mulai": when(p.StartedAt), "dampak": p.Impact, "ack_oleh": p.AckedBy,
	}
	end := time.Now()
	if p.ResolvedAt != nil {
		end = *p.ResolvedAt
	}
	v["durasi"] = dur(end.Sub(p.StartedAt))
	if base != "" {
		v["tautan"] = base + "/problem"
	}
	if delay > 0 {
		v["eskalasi"] = fmt.Sprintf("belum di-ACK %d menit", delay)
	}
	if rb := p.Runbook; rb != nil && kind != "resolve" {
		v["runbook"] = rb.Title + ":\n" + strings.Join(rb.Steps, "\n")
	}
	return v
}

// Render mengganti {penanda}. Penanda tak dikenal dibiarkan apa adanya. Baris yang menjadi kosong karena
// penandanya kosong (mis. {dampak} pada problem bukan down) dibuang.
func Render(tpl string, vals map[string]string, escape func(string) string) string {
	var lines []string
	for _, line := range strings.Split(strings.ReplaceAll(tpl, "\r\n", "\n"), "\n") {
		hadVar, allEmpty := false, true
		var b strings.Builder
		for {
			i := strings.IndexByte(line, '{')
			if i < 0 {
				b.WriteString(escape(line))
				break
			}
			j := strings.IndexByte(line[i:], '}')
			if j < 0 {
				b.WriteString(escape(line))
				break
			}
			name := line[i+1 : i+j]
			b.WriteString(escape(line[:i]))
			if val, ok := vals[name]; ok {
				hadVar = true
				if val != "" {
					allEmpty = false
				}
				b.WriteString(escape(val))
			} else {
				b.WriteString(escape(line[i : i+j+1]))
			}
			line = line[i+j+1:]
		}
		if hadVar && allEmpty {
			continue
		}
		lines = append(lines, b.String())
	}
	return strings.TrimSpace(strings.Join(lines, "\n"))
}

// Templated pesan satu problem dari templat kanal; kosong = format bawaan (Single).
func Templated(c Channel, kind string, p Problem, delay int, base string) Message {
	tpl := c.TemplateOpen
	if kind == "resolve" {
		tpl = c.TemplateResolve
	}
	if strings.TrimSpace(tpl) == "" {
		return Single(kind, p, delay, base)
	}
	vals := templateValues(kind, p, delay, base)
	m := Message{Items: []Problem{p}, Event: "problem.open"}
	if kind == "resolve" {
		m.Event = "problem.resolve"
	}
	m.Text = Render(tpl, vals, func(s string) string { return s })
	m.HTML = Render(tpl, vals, esc)
	subj := m.Text
	if i := strings.IndexByte(subj, '\n'); i >= 0 {
		subj = subj[:i]
	}
	m.Subject = "[ANTAREJA] " + truncate(subj, 180)
	return m
}
