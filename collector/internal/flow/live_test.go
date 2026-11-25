package flow

import (
	"net/netip"
	"testing"
	"time"
)

func TestLiveHanyaPortDitonton(t *testing.T) {
	var l Live
	now := time.Date(2026, 11, 25, 8, 0, 5, 0, time.UTC)
	src := netip.MustParseAddr("10.3.5.20")
	dst := netip.MustParseAddr("10.9.0.5")
	rec := func(b uint64, port uint16) Record {
		return Record{Src: src, Dst: dst, Proto: 6, SrcPort: 50000, DstPort: port, InIf: 2, OutIf: 4, Bytes: b}
	}
	l.add(1, rec(1000, 554), now) // belum ditonton: diabaikan
	l.Watch(1, 4, 'o', now)
	l.add(1, rec(12500, 554), now)                    // 10 kbps di potongan 08:00:00
	l.add(1, rec(2500, 443), now)                     // 2 kbps
	l.add(1, rec(5000, 554), now.Add(10*time.Second)) // potongan berikutnya
	l.add(1, rec(9999, 554), now.Add(20*time.Second)) // potongan berjalan saat snapshot: tidak ikut
	pts := l.Snapshot(1, 4, 'o', "app", 8, now.Add(20*time.Second))
	if len(pts) != LiveSlots {
		t.Fatal(len(pts))
	}
	a, b := pts[len(pts)-2], pts[len(pts)-1]
	if a.T != now.Truncate(LiveSlot).Unix() || a.By["6/554"] != 10000 || a.By["6/443"] != 2000 || a.Total != 12000 {
		t.Fatalf("%+v", a)
	}
	if b.By["6/554"] != 4000 {
		t.Fatalf("%+v", b)
	}
	if s := l.Snapshot(1, 4, 'o', "src", 8, now.Add(20*time.Second)); s[len(s)-2].By["10.3.5.20"] != 12000 {
		t.Fatalf("%+v", s[len(s)-2])
	}
	// port masuk (i) di ifIndex 2 tidak ditonton
	if s := l.Snapshot(1, 2, 'i', "app", 8, now.Add(20*time.Second)); s[len(s)-2].Total != 0 {
		t.Fatal("port tidak ditonton harus kosong")
	}
}
