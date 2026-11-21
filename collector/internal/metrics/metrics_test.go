package metrics

import (
	"testing"
	"time"
)

func TestBatchFormat(t *testing.T) {
	var b Batch
	at := time.UnixMilli(1_700_000_000_123)
	b.Add("antareja_ping_rtt_ms", map[string]string{"device_id": "7"}, 1.25, at)
	b.Add("antareja_if_in_octets_total", map[string]string{"if_name": `Gi0/1 "uplink"`, "device_id": "7", "if_index": "3"}, 123456789012, at)
	want := "antareja_ping_rtt_ms{device_id=\"7\"} 1.25 1700000000123\n" +
		"antareja_if_in_octets_total{device_id=\"7\",if_index=\"3\",if_name=\"Gi0/1 \\\"uplink\\\"\"} 123456789012 1700000000123\n"
	if got := b.String(); got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
}
