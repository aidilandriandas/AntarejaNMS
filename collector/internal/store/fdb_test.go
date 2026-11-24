package store

import (
	"testing"
	"time"
)

func TestPickEdge(t *testing.T) {
	now := time.Now()
	c := func(mac string, dev int64, ifi, n int, ago time.Duration) fdbCand {
		return fdbCand{MACLocation: MACLocation{MAC: mac, DeviceID: dev, IfIndex: ifi}, portMACs: n, seen: now.Add(-ago)}
	}
	got := PickEdge([]fdbCand{
		c("aa", 1, 24, 40, 0), // uplink tanpa LLDP: terlalu banyak MAC
		c("aa", 2, 5, 1, 0),   // port tepi kamera
		c("bb", 1, 3, 2, time.Minute),
		c("bb", 2, 7, 2, 0), // seri jumlah MAC: yang terbaru
		c("cc", 1, 24, 40, 0),
	}, 12)
	if got["aa"].DeviceID != 2 || got["aa"].IfIndex != 5 {
		t.Errorf("aa di port tepi: %+v", got["aa"])
	}
	if got["bb"].DeviceID != 2 {
		t.Errorf("bb terbaru: %+v", got["bb"])
	}
	if _, ok := got["cc"]; ok {
		t.Error("MAC yang hanya terlihat di uplink tidak diberi lokasi")
	}
}
