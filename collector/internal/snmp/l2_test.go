package snmp

import "testing"

func TestBridgeID(t *testing.T) {
	if got := BridgeID([]byte{0x80, 0x00, 0x00, 0x1a, 0x2b, 0x3c, 0x4d, 0x5e}); got != "32768/00:1a:2b:3c:4d:5e" {
		t.Fatal(got)
	}
	if BridgeID([]byte{1, 2}) != "" {
		t.Fatal("panjang salah")
	}
}
