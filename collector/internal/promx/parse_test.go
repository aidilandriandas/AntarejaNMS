package promx

import (
	"math"
	"strings"
	"testing"
)

func TestParse(t *testing.T) {
	in := `# HELP node_load1 1m load average.
# TYPE node_load1 gauge
node_load1 0.42
node_filesystem_avail_bytes{device="/dev/sda1",fstype="ext4",mountpoint="/"} 1.2e+10
weird_label{path="C:\\data \"x\"\nend",} 7 1700000000000
node_cpu_seconds_total{cpu="0",mode="idle"} +Inf
skipped_metric 1
bad{oops 3
`
	s, err := Parse(strings.NewReader(in), func(n string) bool { return n != "skipped_metric" })
	if err != nil {
		t.Fatal(err)
	}
	if len(s) != 4 {
		t.Fatalf("4 baris sah, dapat %d: %+v", len(s), s)
	}
	if s[0].Name != "node_load1" || s[0].Value != 0.42 || s[0].Labels != nil {
		t.Fatalf("%+v", s[0])
	}
	if s[1].Labels["mountpoint"] != "/" || s[1].Value != 1.2e10 {
		t.Fatalf("%+v", s[1])
	}
	if s[2].Labels["path"] != "C:\\data \"x\"\nend" || s[2].Value != 7 {
		t.Fatalf("escape: %q", s[2].Labels["path"])
	}
	if !math.IsInf(s[3].Value, 1) {
		t.Fatal("+Inf")
	}
}
