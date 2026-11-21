package snmp

import (
	"errors"
	"strings"
	"testing"
	"time"
)

func TestRebooted(t *testing.T) {
	cases := []struct {
		prev, now uint32
		elapsed   time.Duration
		want      bool
	}{
		{100_000, 130_000, 5 * time.Minute, false},          // naik normal
		{9_000_000, 2_000, 5 * time.Minute, true},           // turun → reboot
		{4_294_960_000, 22_704, 5 * time.Minute, false},     // wrap 32-bit wajar
		{4_294_960_000, 900_000_000, 5 * time.Minute, true}, // turun jauh dari perkiraan wrap
	}
	for _, c := range cases {
		if got := Rebooted(c.prev, c.now, c.elapsed); got != c.want {
			t.Errorf("Rebooted(%d,%d,%s)=%v ingin %v", c.prev, c.now, c.elapsed, got, c.want)
		}
	}
}

func TestMacAndText(t *testing.T) {
	if m := mac([]byte{0, 0x1b, 0x21, 0xaa, 0xbb, 0xcc}); m != "00:1b:21:aa:bb:cc" {
		t.Fatal(m)
	}
	if mac([]byte{1, 2}) != "" {
		t.Fatal("mac pendek harus kosong")
	}
	if s := text([]byte("ether1\x00\x00")); s != "ether1" {
		t.Fatalf("%q", s)
	}
}

func TestFriendly(t *testing.T) {
	for in, want := range map[string]string{
		"error reading from socket: read udp 172.18.0.5:60361->10.255.0.9:161: recvfrom: connection refused": "UDP 161 tertutup",
		"request timeout (after 1 retries)":        "timeout",
		"dial udp: lookup nvr-pit-a: no such host": "DNS",
	} {
		if got := Friendly(errors.New(in)); !strings.Contains(got, want) {
			t.Errorf("Friendly(%q) = %q", in, got)
		}
	}
}
