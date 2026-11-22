package syslog

import (
	"testing"
	"time"
)

func TestParse(t *testing.T) {
	now := time.Date(2026, 11, 22, 8, 0, 0, 0, time.UTC)
	cases := []struct {
		in             string
		fac, sev       int
		host, app, txt string
	}{
		{"<30>system,info,account user admin logged in from 10.0.0.9 via ssh", 3, 6, "", "system,info,account", "user admin logged in from 10.0.0.9 via ssh"},
		{"<30>Nov 22 07:59:58 CORE-PIT-A interface,info ether2 link down", 3, 6, "CORE-PIT-A", "interface,info", "ether2 link down"},
		{"<189>123: *Mar  1 00:01:02.123: %LINK-3-UPDOWN: Interface Gi0/1, changed state to down", 23, 5, "", "%LINK-3-UPDOWN", "Interface Gi0/1, changed state to down"},
		{"<189>Nov 22 08:00:00 sw-dist1 45: Nov 22 08:00:00.512 WIB: %SYS-5-CONFIG_I: Configured from console by admin on vty0", 23, 5, "sw-dist1", "%SYS-5-CONFIG_I", "Configured from console by admin on vty0"},
		{"<13>1 2026-11-22T08:00:00.003Z nvr-01 hikvision 991 ID47 [exampleSDID@32473 iut=\"3\" eventSource=\"App\"] Disk error", 1, 5, "nvr-01", "hikvision", "Disk error"},
		{"<14>1 2026-11-22T08:00:00Z srv01 sshd - - - Accepted password for root", 1, 6, "srv01", "sshd", "Accepted password for root"},
		{"<38>Nov  3 01:02:03 ups1 sshd[812]: Failed password for invalid user", 4, 6, "ups1", "sshd", "Failed password for invalid user"},
		{"pesan tanpa PRI", -1, 5, "", "", "pesan tanpa PRI"},
		{"<999>aneh", -1, 5, "", "", "<999>aneh"},
	}
	for _, c := range cases {
		m := Parse([]byte(c.in+"\n"), "10.0.0.1", now)
		if m.Facility != c.fac || m.Severity != c.sev || m.Host != c.host || m.App != c.app || m.Text != c.txt {
			t.Errorf("%q\n  dapat fac=%d sev=%d host=%q app=%q txt=%q", c.in, m.Facility, m.Severity, m.Host, m.App, m.Text)
		}
	}
	long := make([]byte, 5000)
	for i := range long {
		long[i] = 'a'
	}
	if m := Parse(long, "x", now); len(m.Text) > maxText+4 {
		t.Errorf("teks tidak dipotong: %d", len(m.Text))
	}
	if m := Parse([]byte{'<', '3', '>', 0xff, 'x'}, "x", now); m.Text != "?x" {
		t.Errorf("utf8: %q", m.Text)
	}
}
