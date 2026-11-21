package api

import "testing"

func TestValidHost(t *testing.T) {
	for h, want := range map[string]bool{
		"10.10.1.1": true, "fe80::1": true, "core-sw.pit-a.local": true, "snmpd": true,
		"": false, "a b": false, "http://x": false, "x/y": false, "user@host": false,
	} {
		if validHost(h) != want {
			t.Errorf("validHost(%q) != %v", h, want)
		}
	}
}

func TestTokenStable(t *testing.T) {
	k := []byte("0123456789abcdef0123456789abcdef")
	// nilai pembanding dihitung dengan PHP: hash_hmac('sha256', 'antareja-collector-api', $k)
	if got := Token(k); got != "f90bdccd584103bc631aab453f4957a6cde515faf9cc9c437df391038689aaba" {
		t.Fatalf("token %s", got)
	}
}
