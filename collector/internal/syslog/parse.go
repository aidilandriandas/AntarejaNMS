// Package syslog menerima log dari perangkat (UDP/TCP, RFC 3164 & RFC 5424), mencocokkan IP pengirim ke
// perangkat terdaftar, lalu menyimpannya per batch ke PostgreSQL. Hanya menerima; tidak pernah menghubungi perangkat.
package syslog

import (
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

// Message adalah satu baris log yang sudah diurai.
type Message struct {
	Received time.Time
	Source   string // IP pengirim
	Facility int    // 0–23; -1 bila tidak ada PRI
	Severity int    // 0 emerg … 7 debug
	Host     string // hostname yang dikirim perangkat (bisa kosong)
	App      string // tag/program, mis. "system,info", "%LINK-3-UPDOWN"
	Text     string
}

var months = map[string]time.Month{"Jan": 1, "Feb": 2, "Mar": 3, "Apr": 4, "May": 5, "Jun": 6, "Jul": 7, "Aug": 8, "Sep": 9, "Oct": 10, "Nov": 11, "Dec": 12}

const maxText = 2000

// Parse mengurai satu pesan secara toleran: format yang tidak dikenal tetap disimpan utuh sebagai teks.
// Waktu pesan dari perangkat diabaikan (jam perangkat sering salah); yang dipakai waktu terima.
func Parse(b []byte, src string, now time.Time) Message {
	s := strings.TrimRight(string(b), "\r\n\x00 ")
	if !utf8.ValidString(s) {
		s = strings.ToValidUTF8(s, "?")
	}
	m := Message{Received: now, Source: src, Facility: -1, Severity: 5}
	// <PRI>
	if len(s) > 2 && s[0] == '<' {
		if end := strings.IndexByte(s, '>'); end > 1 && end <= 4 {
			if pri, err := strconv.Atoi(s[1:end]); err == nil && pri >= 0 && pri <= 191 {
				m.Facility, m.Severity = pri/8, pri%8
				s = s[end+1:]
			}
		}
	}
	if strings.HasPrefix(s, "1 ") { // RFC 5424: VERSION TIMESTAMP HOST APP PROCID MSGID [SD] MSG
		f := strings.SplitN(s[2:], " ", 6)
		if len(f) == 6 {
			m.Host, m.App = nil5424(f[1]), nil5424(f[2])
			rest := f[5]
			if strings.HasPrefix(rest, "-") {
				rest = strings.TrimPrefix(rest[1:], " ")
			} else if strings.HasPrefix(rest, "[") {
				rest = skipSD(rest)
			}
			m.Text = strings.TrimPrefix(rest, "\uFEFF")
			return clip(m)
		}
	}
	// RFC 3164: [TIMESTAMP] [HOST] TAG: MSG — keduanya sering tidak ada (MikroTik tanpa "BSD syslog", Cisco dengan nomor urut)
	s = skipCiscoSeq(s)
	if rest, ok := skip3164Time(s); ok {
		s = rest
		// HOST hanya bila kata berikutnya bukan tag (tag diakhiri ':' atau '[')
		if sp := strings.IndexByte(s, ' '); sp > 0 {
			w := s[:sp]
			if !strings.HasSuffix(w, ":") && !strings.ContainsAny(w, "[%") {
				m.Host, s = w, s[sp+1:]
			}
		}
	}
	s = skipCiscoSeq(s)
	if rest, ok := skip3164Time(s); ok { // Cisco: "*Mar  1 00:01:02.123: %LINK..."
		s = strings.TrimPrefix(rest, ": ")
	}
	// TAG
	if c := strings.Index(s, ": "); c > 0 && c <= 64 && !strings.Contains(s[:c], " ") {
		m.App = s[:c]
		if i := strings.IndexByte(m.App, '['); i > 0 {
			m.App = m.App[:i]
		}
		s = s[c+2:]
	}
	// MikroTik: topik di depan pesan, mis. "system,info,account user admin logged in"
	if m.App == "" {
		if sp := strings.IndexByte(s, ' '); sp > 0 && isTopics(s[:sp]) {
			m.App, s = s[:sp], s[sp+1:]
		}
	}
	m.Text = strings.TrimSpace(s)
	return clip(m)
}

func isTopics(w string) bool {
	if !strings.Contains(w, ",") || len(w) > 64 {
		return false
	}
	for _, r := range w {
		if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == ',' || r == '-') {
			return false
		}
	}
	return !strings.HasPrefix(w, ",") && !strings.HasSuffix(w, ",")
}

func clip(m Message) Message {
	if len(m.Text) > maxText {
		m.Text = strings.ToValidUTF8(m.Text[:maxText], "") + "…"
	}
	if len(m.Host) > 128 {
		m.Host = m.Host[:128]
	}
	if len(m.App) > 64 {
		m.App = m.App[:64]
	}
	return m
}

func nil5424(s string) string {
	if s == "-" {
		return ""
	}
	return s
}

// skipSD melewati blok structured data [..][..] (memperhitungkan \] di dalam nilai).
func skipSD(s string) string {
	for strings.HasPrefix(s, "[") {
		i, esc := 1, false
		for ; i < len(s); i++ {
			if esc {
				esc = false
				continue
			}
			if s[i] == '\\' {
				esc = true
			} else if s[i] == ']' {
				break
			}
		}
		if i >= len(s) {
			return ""
		}
		s = s[i+1:]
	}
	return strings.TrimPrefix(s, " ")
}

// skipCiscoSeq melewati nomor urut Cisco "123: ".
func skipCiscoSeq(s string) string {
	i := 0
	for i < len(s) && s[i] >= '0' && s[i] <= '9' {
		i++
	}
	if i > 0 && strings.HasPrefix(s[i:], ": ") {
		return s[i+2:]
	}
	return s
}

// skip3164Time melewati "Mmm dd hh:mm:ss" (opsional tahun, milidetik, zona, awalan '*' / '.').
func skip3164Time(s string) (string, bool) {
	t := strings.TrimLeft(s, "*.")
	if len(t) < 15 {
		return s, false
	}
	if _, ok := months[t[:3]]; !ok || t[3] != ' ' {
		return s, false
	}
	f := strings.Fields(t)
	if len(f) < 3 {
		return s, false
	}
	n := 3              // bulan, tanggal, jam
	if len(f[1]) == 4 { // tahun: "Mmm dd yyyy hh:mm:ss"
		n = 4
	}
	clock := strings.TrimSuffix(f[n-1], ":")
	if strings.Count(clock, ":") < 2 {
		return s, false
	}
	idx := 0
	for i := 0; i < n; i++ {
		j := strings.Index(t[idx:], f[i])
		idx += j + len(f[i])
	}
	rest := strings.TrimLeft(t[idx:], " ")
	// zona waktu opsional (Cisco "UTC:", "WIB:")
	if sp := strings.IndexByte(rest, ' '); sp > 0 && sp <= 5 && strings.HasSuffix(rest[:sp], ":") && strings.ToUpper(rest[:sp]) == rest[:sp] {
		rest = rest[sp+1:]
	}
	return rest, true
}

// SeverityName nama tingkat (untuk log internal).
var SeverityName = []string{"emerg", "alert", "crit", "err", "warning", "notice", "info", "debug"}
