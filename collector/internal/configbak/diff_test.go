package configbak

import (
	"strings"
	"testing"
)

func TestDiff(t *testing.T) {
	a := strings.Split("a\nb\nc\nd\ne\nf\ng\nh\ni\nj", "\n")
	b := strings.Split("a\nb\nC\nd\ne\nf\ng\nh\ni\nj\nk", "\n")
	text, add, rem := Diff(a, b)
	if add != 2 || rem != 1 {
		t.Fatalf("add=%d rem=%d\n%s", add, rem, text)
	}
	if !strings.Contains(text, "-c\n+C\n") || !strings.Contains(text, "+k\n") {
		t.Fatalf("diff:\n%s", text)
	}
	if text, add, rem := Diff(a, a); text != "" || add != 0 || rem != 0 {
		t.Fatal("identik = tanpa diff")
	}
	// dari kosong
	if _, add, rem := Diff(nil, []string{"x", "y"}); add != 2 || rem != 0 {
		t.Fatal("dari kosong")
	}
	if _, add, rem := Diff([]string{"x", "y"}, nil); add != 0 || rem != 2 {
		t.Fatal("ke kosong")
	}
}

func TestNormalize(t *testing.T) {
	mt := "# nov/22/2026 07:00:01 by RouterOS 7.15.3\n# software id = ABCD-1234\n#\n/interface bridge\nadd name=bridge1\n"
	got := Normalize("mikrotik", mt)
	if strings.Contains(got, "07:00:01") || !strings.Contains(got, "add name=bridge1") || !strings.Contains(got, "software id") {
		t.Fatalf("%q", got)
	}
	ci := "Building configuration...\n\nCurrent configuration : 1234 bytes\n!\n! Last configuration change at 07:00:00 WIB Sat Nov 22 2026 by admin\n! NVRAM config last updated at 06:00:00\nntp clock-period 17179869\nhostname core1\n!\nend\n"
	got = Normalize("cisco", ci)
	for _, bad := range []string{"Building", "Current configuration", "Last configuration change", "NVRAM", "clock-period"} {
		if strings.Contains(got, bad) {
			t.Fatalf("harus dibuang %q:\n%s", bad, got)
		}
	}
	if !strings.Contains(got, "hostname core1") {
		t.Fatal(got)
	}
}
