package notify

import (
	"encoding/base64"
	"fmt"
	"html"
	"strings"
	"time"
)

var sevIcon = map[string]string{"disaster": "🟥", "high": "🔴", "average": "🟠", "warning": "🟡", "info": "🔵"}
var sevLabel = map[string]string{"disaster": "DISASTER", "high": "HIGH", "average": "AVERAGE", "warning": "WARNING", "info": "INFO"}

func esc(s string) string { return html.EscapeString(s) }

func when(t time.Time) string { return t.Local().Format("02/01 15.04") }

// Durasi singkat bahasa Indonesia.
func dur(d time.Duration) string {
	m := int(d.Minutes())
	switch {
	case m < 1:
		return "< 1 menit"
	case m < 60:
		return fmt.Sprintf("%d menit", m)
	case m < 1440:
		if m%60 == 0 {
			return fmt.Sprintf("%d jam", m/60)
		}
		return fmt.Sprintf("%d jam %d menit", m/60, m%60)
	default:
		return fmt.Sprintf("%d hari %d jam", m/1440, m%1440/60)
	}
}

func where(p Problem) string {
	s := p.Device + " (" + p.Host + ")"
	if p.Site != "" {
		s += " · " + p.Site
	}
	return s
}

// Single membuat pesan untuk satu problem.
func Single(kind string, p Problem, delay int, base string) Message {
	m := Message{Items: []Problem{p}}
	link := ""
	if base != "" {
		link = base + "/problem"
	}
	if kind == "resolve" {
		d := time.Duration(0)
		if p.ResolvedAt != nil {
			d = p.ResolvedAt.Sub(p.StartedAt)
		}
		m.Event = "problem.resolve"
		m.Subject = "[ANTAREJA] PULIH: " + p.Title
		m.Text = fmt.Sprintf("PULIH · %s\n%s\nLama gangguan: %s", p.Title, where(p), dur(d))
		m.HTML = fmt.Sprintf("✅ <b>PULIH</b>\n<b>%s</b>\n%s\nLama gangguan: %s", esc(p.Title), esc(where(p)), dur(d))
	} else {
		m.Event = "problem.open"
		head := "PROBLEM · " + sevLabel[p.Severity]
		if delay > 0 {
			head = fmt.Sprintf("ESKALASI · belum di-ACK %d menit · %s", delay, sevLabel[p.Severity])
		}
		m.Subject = "[ANTAREJA] " + head + ": " + p.Title
		m.Text = fmt.Sprintf("%s\n%s\n%s\n%s\nMulai %s", head, p.Title, where(p), p.Detail, when(p.StartedAt))
		icon := sevIcon[p.Severity]
		if delay > 0 {
			icon = "⏫ " + icon
		}
		m.HTML = fmt.Sprintf("%s <b>%s</b>\n<b>%s</b>\n%s\n%s\n<i>Mulai %s</i>", icon, esc(head), esc(p.Title), esc(where(p)), esc(p.Detail), when(p.StartedAt))
		if p.Impact != "" {
			m.Text += "\nDampak: " + p.Impact
			m.HTML += "\n<b>Dampak:</b> " + esc(p.Impact)
		}
		if rb := p.Runbook; rb != nil {
			m.Text += "\n\nLangkah penanganan (" + rb.Title + "):\n" + strings.Join(rb.Steps, "\n")
			m.HTML += "\n\n<b>Langkah penanganan</b> (" + esc(rb.Title) + "):"
			for _, s := range rb.Steps {
				m.HTML += "\n" + esc(s)
			}
			if base != "" {
				u := fmt.Sprintf("%s/runbook/%d", base, rb.ID)
				m.Text += "\nRunbook lengkap: " + u
				m.HTML += "\nRunbook lengkap: " + esc(u)
			}
		}
	}
	if link != "" {
		m.Text += "\n" + link
		m.HTML += "\n" + esc(link)
	}
	return m
}

// Summary membuat satu pesan untuk banyak problem sekaligus (mis. site padam).
func Summary(kind string, ps []Problem, delay int, base string) Message {
	m := Message{Event: "summary", Items: ps}
	verb, icon := "problem baru", "🚨"
	if kind == "resolve" {
		verb, icon = "problem pulih", "✅"
	} else if delay > 0 {
		verb, icon = fmt.Sprintf("problem belum di-ACK %d menit (eskalasi)", delay), "⏫"
	}
	sites := map[string]int{}
	for _, p := range ps {
		sites[p.Site]++
	}
	var siteParts []string
	for s, n := range sites {
		if s == "" {
			s = "tanpa site"
		}
		siteParts = append(siteParts, fmt.Sprintf("%s: %d", s, n))
	}
	head := fmt.Sprintf("%d %s", len(ps), verb)
	m.Subject = "[ANTAREJA] " + head
	var tl, hl []string
	for i, p := range ps {
		if i == 15 {
			tl = append(tl, fmt.Sprintf("… dan %d lainnya", len(ps)-15))
			hl = append(hl, fmt.Sprintf("… dan %d lainnya", len(ps)-15))
			break
		}
		tl = append(tl, fmt.Sprintf("- [%s] %s (%s)", sevLabel[p.Severity], p.Title, p.Site))
		hl = append(hl, fmt.Sprintf("%s %s <i>%s</i>", sevIcon[p.Severity], esc(p.Title), esc(p.Site)))
	}
	m.Text = head + " (" + strings.Join(siteParts, ", ") + ")\n" + strings.Join(tl, "\n")
	m.HTML = fmt.Sprintf("%s <b>%s</b>\n%s\n\n%s", icon, esc(head), esc(strings.Join(siteParts, " · ")), strings.Join(hl, "\n"))
	if base != "" {
		m.Text += "\n" + base + "/problem"
		m.HTML += "\n" + esc(base+"/problem")
	}
	return m
}

func b64(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
