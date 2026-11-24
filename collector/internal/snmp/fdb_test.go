package snmp

import "testing"

func TestMacFromIdx(t *testing.T) {
	cases := map[string]string{"0.17.34.51.68.85": "00:11:22:33:44:55", "200.12.0.1.2.3": "c8:0c:00:01:02:03"}
	for in, want := range cases {
		if got, ok := macFromIdx(split(in)); !ok || got != want {
			t.Errorf("%s: %q %v", in, got, ok)
		}
	}
	for _, bad := range []string{"1.0.94.0.0.1", "0.0.0.0.0.0", "1.2.3", "0.17.34.51.68.300"} {
		if _, ok := macFromIdx(split(bad)); ok {
			t.Errorf("%s harus ditolak (multicast/kosong/rusak)", bad)
		}
	}
}

func split(s string) []string {
	var out []string
	cur := ""
	for _, r := range s {
		if r == '.' {
			out = append(out, cur)
			cur = ""
			continue
		}
		cur += string(r)
	}
	return append(out, cur)
}
