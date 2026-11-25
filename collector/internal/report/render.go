package report

import (
	"bytes"
	"fmt"
	"html"
	"html/template"
	"math"
	"strings"
	"time"
)

var freqName = map[string]string{"daily": "harian", "weekly": "mingguan", "monthly": "bulanan"}

func pct(v float64) string {
	if v < 0 {
		return "tidak ada"
	}
	if v >= 99.995 && v < 100 {
		return "99,99%"
	}
	s := fmt.Sprintf("%.2f", math.Floor(v*100)/100)
	return strings.Replace(s, ".", ",", 1) + "%"
}

func durText(sec float64) string {
	d := time.Duration(sec) * time.Second
	if d <= 0 {
		return "0"
	}
	h := int(d.Hours())
	m := int(d.Minutes()) % 60
	switch {
	case h >= 24:
		return fmt.Sprintf("%d hr %d j", h/24, h%24)
	case h > 0:
		return fmt.Sprintf("%d j %d m", h, m)
	case m > 0:
		return fmt.Sprintf("%d m", m)
	}
	return fmt.Sprintf("%d dtk", int(d.Seconds()))
}

func tone(v, target float64) string {
	switch {
	case v < 0:
		return "#6b7280"
	case v >= target:
		return "#047857"
	case v >= target-1:
		return "#b45309"
	}
	return "#b91c1c"
}

// Subject judul email.
func Subject(freq string, d Data) string {
	scope := "semua site"
	if d.SiteName != "" {
		scope = d.SiteName
	}
	return fmt.Sprintf("[ANTAREJA] Laporan %s %s, %s, ketersediaan %s", freqName[freq], d.Period.Label, scope, pct(d.Total.Avail))
}

var sevOrder = []struct{ Key, Label, Color string }{
	{"disaster", "Disaster", "#7f1d1d"}, {"high", "High", "#b91c1c"}, {"average", "Average", "#c2410c"}, {"warning", "Warning", "#b45309"}, {"info", "Info", "#1d4ed8"},
}

var tpl = template.Must(template.New("r").Funcs(template.FuncMap{
	"pct": pct, "dur": durText, "tone": tone,
	"since": func(t time.Time) string { return t.Local().Format("02/01 15.04") },
	"sevs": func(m map[string]int) template.HTML {
		var parts []string
		for _, s := range sevOrder {
			if n := m[s.Key]; n > 0 {
				parts = append(parts, fmt.Sprintf(`<span style="color:%s">%s %d</span>`, s.Color, s.Label, n))
			}
		}
		return template.HTML(strings.Join(parts, ", ")) // label & warna konstanta, angka bilangan bulat — aman
	},
}).Parse(`<!doctype html><html><body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#111827">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">
<tr><td style="background:#111827;padding:18px 24px;color:#ffffff">
  <div style="font:700 16px/1.2 Segoe UI,Arial,sans-serif;letter-spacing:.06em">ANTAREJA NMS</div>
  <div style="font-size:13px;color:#9ca3af;margin-top:4px">Laporan {{.Freq}}, {{.D.Period.Label}}, {{if .D.SiteName}}{{.D.SiteName}}{{else}}semua site{{end}}</div>
</td></tr>
<tr><td style="padding:22px 24px 6px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td style="padding:12px;border:1px solid #e5e7eb;border-radius:8px" width="33%">
      <div style="font-size:11px;color:#6b7280;letter-spacing:.08em;text-transform:uppercase">Ketersediaan</div>
      <div style="font:700 26px Segoe UI,Arial,sans-serif;color:{{tone .D.Total.Avail .D.Target}}">{{pct .D.Total.Avail}}</div>
      <div style="font-size:12px;color:#6b7280">target {{pct .D.Target}}, {{.D.Total.Devices}} perangkat</div></td>
    <td width="10"></td>
    <td style="padding:12px;border:1px solid #e5e7eb;border-radius:8px" width="33%">
      <div style="font-size:11px;color:#6b7280;letter-spacing:.08em;text-transform:uppercase">Gangguan down</div>
      <div style="font:700 26px Segoe UI,Arial,sans-serif">{{.D.Total.Incidents}}</div>
      <div style="font-size:12px;color:#6b7280">total {{dur .D.Total.Down}}, {{.D.BelowCount}} di bawah target</div></td>
    <td width="10"></td>
    <td style="padding:12px;border:1px solid #e5e7eb;border-radius:8px" width="33%">
      <div style="font-size:11px;color:#6b7280;letter-spacing:.08em;text-transform:uppercase">Sekarang</div>
      <div style="font:700 26px Segoe UI,Arial,sans-serif;color:{{if .D.DownNow}}#b91c1c{{else}}#047857{{end}}">{{len .D.DownNow}} down</div>
      <div style="font-size:12px;color:#6b7280">{{.D.OpenNow}} problem terbuka{{if .D.OpenHigh}}, <b style="color:#b91c1c">{{.D.OpenHigh}} High+ belum ACK</b>{{end}}</div></td>
  </tr></table>
</td></tr>
{{if .D.DownNow}}<tr><td style="padding:14px 24px 0">
  <div style="font:600 14px Segoe UI,Arial,sans-serif;margin-bottom:6px;color:#b91c1c">Sedang down saat laporan dibuat</div>
  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse">
  {{range .D.DownNow}}<tr style="border-top:1px solid #f3f4f6"><td><b>{{.Name}}</b> <span style="color:#6b7280">{{.Host}}</span></td><td style="color:#6b7280">{{.Site}}</td><td align="right" style="color:#6b7280">sejak {{since .Since}}</td></tr>{{end}}
  </table></td></tr>{{end}}
{{if gt (len .D.Sites) 1}}<tr><td style="padding:18px 24px 0">
  <div style="font:600 14px Segoe UI,Arial,sans-serif;margin-bottom:6px">Per site</div>
  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse">
  <tr style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.06em"><td>Site</td><td align="right">Perangkat</td><td align="right">Gangguan</td><td align="right">Downtime</td><td align="right">Ketersediaan</td></tr>
  {{range .D.Sites}}<tr style="border-top:1px solid #f3f4f6"><td><b>{{.Site}}</b></td><td align="right">{{.Devices}}</td><td align="right">{{.Incidents}}</td><td align="right">{{dur .Down}}</td><td align="right" style="font-weight:600;color:{{tone .Avail $.D.Target}}">{{pct .Avail}}</td></tr>{{end}}
  </table></td></tr>{{end}}
{{if .D.Services}}<tr><td style="padding:18px 24px 0">
  <div style="font:600 14px Segoe UI,Arial,sans-serif;margin-bottom:6px">Layanan</div>
  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse">
  <tr style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.06em"><td>Layanan</td><td align="right">Gangguan</td><td align="right">Downtime</td><td align="right">Target</td><td align="right">Ketersediaan</td></tr>
  {{range .D.Services}}<tr style="border-top:1px solid #f3f4f6"><td><b>{{.Name}}</b>{{if gt .Degraded 0.0}}<br><span style="color:#6b7280;font-size:12px">tanpa cadangan {{dur .Degraded}}</span>{{end}}</td><td align="right">{{.Outages}}</td><td align="right">{{dur .Down}}</td><td align="right" style="color:#6b7280">{{pct .Target}}</td><td align="right" style="font-weight:600;color:{{tone .Avail .Target}}">{{pct .Avail}}</td></tr>{{end}}
  </table></td></tr>{{end}}
<tr><td style="padding:18px 24px 0">
  <div style="font:600 14px Segoe UI,Arial,sans-serif;margin-bottom:6px">Perangkat dengan downtime terbesar</div>
  {{if .D.Worst}}<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse">
  <tr style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.06em"><td>Perangkat</td><td>Site</td><td align="right">Gangguan</td><td align="right">Downtime</td><td align="right">Ketersediaan</td></tr>
  {{range .D.Worst}}<tr style="border-top:1px solid #f3f4f6"><td><b>{{.Name}}</b><br><span style="color:#6b7280;font-size:12px">{{.Host}}</span></td><td style="color:#6b7280">{{.Site}}</td><td align="right">{{.Incidents}}</td><td align="right">{{dur .Down}}</td><td align="right" style="font-weight:600;color:{{tone .Avail $.D.Target}}">{{pct .Avail}}</td></tr>{{end}}
  </table>{{else}}<div style="font-size:13px;color:#047857">Tidak ada perangkat down selama periode ini. 👍</div>{{end}}
</td></tr>
<tr><td style="padding:18px 24px 0">
  <div style="font:600 14px Segoe UI,Arial,sans-serif;margin-bottom:6px">Aktivitas periode ini</div>
  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse">
  <tr style="border-top:1px solid #f3f4f6"><td>Problem baru</td><td align="right"><b>{{.D.ProblemsN}}</b>{{if .D.ProblemsN}} <span style="color:#6b7280">({{sevs .D.Problems}})</span>{{end}}</td></tr>
  <tr style="border-top:1px solid #f3f4f6"><td>Reboot terdeteksi</td><td align="right"><b>{{.D.Reboots}}</b></td></tr>
  <tr style="border-top:1px solid #f3f4f6"><td>Perubahan konfigurasi (backup SSH)</td><td align="right"><b>{{.D.ConfigChg}}</b></td></tr>
  <tr style="border-top:1px solid #f3f4f6"><td>Syslog Error ke atas</td><td align="right"><b>{{.D.SyslogErr}}</b></td></tr>
  </table>
</td></tr>
<tr><td style="padding:16px 24px 0">
  <div style="font:600 14px Segoe UI,Arial,sans-serif;margin-bottom:6px">Insiden</div>
  {{if .D.IncN}}<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse">
  <tr style="border-top:1px solid #f3f4f6"><td>Insiden dibuka</td><td align="right"><b>{{.D.IncN}}</b>{{if .D.IncActive}} <span style="color:#b91c1c">({{.D.IncActive}} masih berlangsung)</span>{{end}}</td></tr>
  <tr style="border-top:1px solid #f3f4f6"><td>Rata-rata sampai ditangani (MTTA)</td><td align="right"><b>{{if .D.IncMTTA}}{{dur .D.IncMTTA}}{{else}}belum ada{{end}}</b></td></tr>
  <tr style="border-top:1px solid #f3f4f6"><td>Rata-rata sampai pulih (MTTR)</td><td align="right"><b>{{if .D.IncMTTR}}{{dur .D.IncMTTR}}{{else}}belum ada{{end}}</b></td></tr>
  {{if .D.IncNoRCA}}<tr style="border-top:1px solid #f3f4f6"><td>Pulih tetapi penyebab belum diisi</td><td align="right"><b style="color:#b45309">{{.D.IncNoRCA}}</b></td></tr>{{end}}
  </table>
  <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse;margin-top:8px">
  {{range .D.IncTop}}<tr style="border-top:1px solid #f3f4f6"><td><b>#{{.ID}} {{.Title}}</b><br><span style="color:#6b7280;font-size:12px">{{if .Site}}{{.Site}}, {{end}}{{.Severity}}{{if .Cause}}, penyebab: {{.Cause}}{{end}}</span></td><td align="right" style="white-space:nowrap">{{dur .Seconds}}</td></tr>{{end}}
  </table>{{else}}<div style="font-size:13px;color:#6b7280">Tidak ada insiden pada periode ini.</div>{{end}}
</td></tr>
<tr><td style="padding:20px 24px 22px;font-size:12px;color:#6b7280">
  Downtime dihitung dari problem <i>down</i> dan tidak termasuk jendela maintenance.{{if .Link}} <a href="{{.Link}}" style="color:#0f766e">Buka laporan SLA lengkap</a>.{{end}}<br>
  Dibuat otomatis {{since .D.Generated}} oleh ANTAREJA NMS.
</td></tr>
</table></td></tr></table></body></html>`))

// HTML isi email lengkap.
func HTML(freq string, d Data) (string, error) {
	var b bytes.Buffer
	link := ""
	if d.BaseURL != "" {
		link = d.BaseURL + "/laporan/sla?bulan=" + d.Period.Start.Format("2006-01")
	}
	err := tpl.Execute(&b, map[string]any{"D": d, "Freq": freqName[freq], "Link": link})
	return b.String(), err
}

// Text versi teks polos (email alternatif / webhook).
func Text(freq string, d Data) string {
	var b strings.Builder
	scope := "semua site"
	if d.SiteName != "" {
		scope = d.SiteName
	}
	fmt.Fprintf(&b, "ANTAREJA NMS: laporan %s %s (%s)\n\n", freqName[freq], d.Period.Label, scope)
	fmt.Fprintf(&b, "Ketersediaan: %s (target %s), %d perangkat\n", pct(d.Total.Avail), pct(d.Target), d.Total.Devices)
	fmt.Fprintf(&b, "Gangguan down: %d, total downtime %s, %d perangkat di bawah target\n", d.Total.Incidents, durText(d.Total.Down), d.BelowCount)
	fmt.Fprintf(&b, "Sekarang: %d down, %d problem terbuka (%d High+ belum ACK)\n", len(d.DownNow), d.OpenNow, d.OpenHigh)
	if len(d.Sites) > 1 {
		b.WriteString("\nPer site:\n")
		for _, s := range d.Sites {
			fmt.Fprintf(&b, "- %s: %s, %d gangguan, downtime %s\n", s.Site, pct(s.Avail), s.Incidents, durText(s.Down))
		}
	}
	if len(d.Services) > 0 {
		b.WriteString("\nLayanan:\n")
		for _, x := range d.Services {
			fmt.Fprintf(&b, "- %s: %s (target %s), %d gangguan, downtime %s\n", x.Name, pct(x.Avail), pct(x.Target), x.Outages, durText(x.Down))
		}
	}
	if d.IncN > 0 {
		fmt.Fprintf(&b, "Insiden: %d dibuka (%d masih berlangsung), MTTA %s, MTTR %s\n", d.IncN, d.IncActive, durText(d.IncMTTA), durText(d.IncMTTR))
	}
	if len(d.Worst) > 0 {
		b.WriteString("\nDowntime terbesar:\n")
		for _, x := range d.Worst {
			fmt.Fprintf(&b, "- %s (%s, %s): %s, %d gangguan, downtime %s\n", x.Name, x.Host, x.Site, pct(x.Avail), x.Incidents, durText(x.Down))
		}
	}
	if len(d.DownNow) > 0 {
		b.WriteString("\nSedang down:\n")
		for _, x := range d.DownNow {
			fmt.Fprintf(&b, "- %s (%s) sejak %s\n", x.Name, x.Host, x.Since.Local().Format("02/01 15.04"))
		}
	}
	fmt.Fprintf(&b, "\nProblem baru %d, reboot %d, perubahan konfigurasi %d, syslog error %d\n", d.ProblemsN, d.Reboots, d.ConfigChg, d.SyslogErr)
	return b.String()
}

// Telegram versi ringkas (subset HTML Telegram).
func Telegram(freq string, d Data) string {
	var b strings.Builder
	scope := "semua site"
	if d.SiteName != "" {
		scope = d.SiteName
	}
	fmt.Fprintf(&b, "📊 <b>Laporan %s</b>, %s\n<i>%s</i>\n\n", freqName[freq], html.EscapeString(d.Period.Label), html.EscapeString(scope))
	fmt.Fprintf(&b, "Ketersediaan <b>%s</b> (target %s), %d perangkat\n", pct(d.Total.Avail), pct(d.Target), d.Total.Devices)
	fmt.Fprintf(&b, "Gangguan %d, downtime %s\n", d.Total.Incidents, durText(d.Total.Down))
	fmt.Fprintf(&b, "Sekarang: <b>%d down</b>, %d problem terbuka\n", len(d.DownNow), d.OpenNow)
	if d.IncN > 0 {
		fmt.Fprintf(&b, "Insiden %d (%d berlangsung), MTTR %s\n", d.IncN, d.IncActive, durText(d.IncMTTR))
	}
	if len(d.Services) > 0 {
		b.WriteString("\n<b>Layanan</b>\n")
		for i, x := range d.Services {
			if i >= 8 {
				break
			}
			mark := ""
			if x.Avail >= 0 && x.Avail < x.Target {
				mark = " ⚠"
			}
			fmt.Fprintf(&b, "• %s: %s%s\n", html.EscapeString(x.Name), pct(x.Avail), mark)
		}
	}
	if len(d.Worst) > 0 {
		b.WriteString("\n<b>Downtime terbesar</b>\n")
		for i, x := range d.Worst {
			if i >= 5 {
				break
			}
			fmt.Fprintf(&b, "• %s: %s (%s)\n", html.EscapeString(x.Name), pct(x.Avail), durText(x.Down))
		}
	}
	return b.String()
}
