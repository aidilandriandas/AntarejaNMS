package flow

import (
	"encoding/binary"
	"net/netip"
	"testing"
	"time"
)

var exp = netip.MustParseAddr("10.0.0.1")

func be16(v uint16) []byte { return binary.BigEndian.AppendUint16(nil, v) }
func be32(v uint32) []byte { return binary.BigEndian.AppendUint32(nil, v) }

func cat(parts ...[]byte) []byte {
	var out []byte
	for _, p := range parts {
		out = append(out, p...)
	}
	return out
}

func TestV5(t *testing.T) {
	hdr := cat(be16(5), be16(1), be32(0), be32(0), be32(0), be32(0), []byte{0, 0}, be16(0x4000|10)) // sampling 1:10
	rec := make([]byte, 48)
	copy(rec[0:], []byte{192, 168, 1, 10})
	copy(rec[4:], []byte{10, 1, 2, 3})
	binary.BigEndian.PutUint16(rec[12:], 3)
	binary.BigEndian.PutUint16(rec[14:], 7)
	binary.BigEndian.PutUint32(rec[16:], 5)
	binary.BigEndian.PutUint32(rec[20:], 1500)
	binary.BigEndian.PutUint16(rec[32:], 51234)
	binary.BigEndian.PutUint16(rec[34:], 443)
	rec[38] = 6
	rs, err := NewDecoder().Decode(exp, cat(hdr, rec))
	if err != nil || len(rs) != 1 {
		t.Fatalf("%v %v", rs, err)
	}
	r := rs[0]
	if r.Src.String() != "192.168.1.10" || r.Dst.String() != "10.1.2.3" || r.Bytes != 15000 || r.Packets != 50 || r.InIf != 3 || r.OutIf != 7 || r.DstPort != 443 || r.Proto != 6 {
		t.Fatalf("v5: %+v", r)
	}
	if _, err := NewDecoder().Decode(exp, hdr[:10]); err == nil {
		t.Fatal("paket terpotong harus galat")
	}
}

func TestV9TemplateDanSampling(t *testing.T) {
	d := NewDecoder()
	hdr := func(count uint16) []byte { return cat(be16(9), be16(count), be32(0), be32(0), be32(1), be32(77)) }
	// template 256: src v4, dst v4, proto, srcport, dstport, in, out, bytes(8), packets(4)
	fields := [][2]uint16{{8, 4}, {12, 4}, {4, 1}, {7, 2}, {11, 2}, {10, 2}, {14, 2}, {1, 8}, {2, 4}}
	tpl := cat(be16(256), be16(uint16(len(fields))))
	for _, f := range fields {
		tpl = cat(tpl, be16(f[0]), be16(f[1]))
	}
	tset := cat(be16(0), be16(uint16(4+len(tpl))), tpl)
	// template opsi 257: scope system(1)=4 byte, sampling interval(34)=4 byte
	opt := cat(be16(257), be16(4), be16(4), be16(1), be16(4), be16(34), be16(4))
	oset := cat(be16(1), be16(uint16(4+len(opt))), opt)
	odata := cat(be32(0), be32(100))
	odset := cat(be16(257), be16(uint16(4+len(odata))), odata)
	rec := cat([]byte{10, 0, 0, 5}, []byte{10, 9, 9, 9}, []byte{17}, be16(40000), be16(53), be16(2), be16(5),
		[]byte{0, 0, 0, 0, 0, 0, 0, 80}, be32(1))
	dset := cat(be16(256), be16(uint16(4+len(rec)+2)), rec, []byte{0, 0}) // + padding
	// data sebelum template: dilewati
	if rs, _ := d.Decode(exp, cat(hdr(1), dset)); len(rs) != 0 {
		t.Fatal("data tanpa template dilewati")
	}
	rs, err := d.Decode(exp, cat(hdr(4), tset, oset, odset, dset))
	if err != nil || len(rs) != 1 {
		t.Fatalf("%v %v", rs, err)
	}
	r := rs[0]
	if r.Bytes != 8000 || r.Packets != 100 || r.DstPort != 53 || r.Proto != 17 || r.InIf != 2 || r.OutIf != 5 {
		t.Fatalf("v9 (sampling 1:100): %+v", r)
	}
	// eksportir lain dengan template ID sama tidak memakai template ini
	if rs, _ := d.Decode(netip.MustParseAddr("10.0.0.2"), cat(hdr(1), dset)); len(rs) != 0 {
		t.Fatal("template per eksportir")
	}
}

func TestIPFIX(t *testing.T) {
	d := NewDecoder()
	// template 300: src v6(16), dst v6(16), proto(1), enterprise field 1000 (len var), dst port(2), bytes(4), in(4)
	tpl := cat(be16(300), be16(7), be16(27), be16(16), be16(28), be16(16), be16(4), be16(1),
		be16(0x8000|1000), be16(0xffff), be32(9999), be16(11), be16(2), be16(1), be16(4), be16(10), be16(4))
	tset := cat(be16(2), be16(uint16(4+len(tpl))), tpl)
	src := netip.MustParseAddr("2001:db8::1").As16()
	dst := netip.MustParseAddr("2001:db8::2").As16()
	rec := cat(src[:], dst[:], []byte{6}, []byte{3, 'a', 'b', 'c'}, be16(8080), be32(4096), be32(9))
	dset := cat(be16(300), be16(uint16(4+len(rec))), rec)
	body := cat(tset, dset)
	msg := cat(be16(10), be16(uint16(16+len(body))), be32(0), be32(0), be32(5), body)
	rs, err := d.Decode(exp, msg)
	if err != nil || len(rs) != 1 {
		t.Fatalf("%v %v", rs, err)
	}
	if rs[0].Src.String() != "2001:db8::1" || rs[0].DstPort != 8080 || rs[0].Bytes != 4096 || rs[0].InIf != 9 {
		t.Fatalf("ipfix: %+v", rs[0])
	}
}

func TestServicePortDanAgregasi(t *testing.T) {
	for _, c := range []struct {
		p      uint8
		sp, dp uint16
		want   uint16
	}{{6, 51000, 443, 443}, {6, 443, 51000, 443}, {17, 40000, 3306, 3306}, {6, 40000, 50000, 0}, {1, 0, 0, 0}} {
		if got := ServicePort(c.p, c.sp, c.dp); got != c.want {
			t.Errorf("%+v: %d", c, got)
		}
	}
	var a Aggregator
	now := time.Date(2026, 11, 24, 10, 1, 0, 0, time.UTC)
	for i := 0; i < TopN+20; i++ {
		src := netip.AddrFrom4([4]byte{10, 0, byte(i / 250), byte(i % 250)})
		a.add(1, Record{Src: src, Dst: netip.MustParseAddr("8.8.8.8"), Proto: 6, SrcPort: 50000, DstPort: 443, InIf: 2, Bytes: uint64(1000 + i), Packets: 1}, now)
	}
	if rows := a.Drain(now); rows != nil {
		t.Fatal("bucket berjalan belum dikeluarkan")
	}
	rows := a.Drain(now.Add(5 * time.Minute))
	if len(rows) != TopN+1 {
		t.Fatalf("%d teratas + 1 lainnya, dapat %d", TopN, len(rows))
	}
	var other Row
	for _, r := range rows {
		if !r.Src.IsValid() {
			other = r
		}
	}
	if other.Packets != 20 || other.Bucket != now.Truncate(Bucket) {
		t.Fatalf("baris lainnya: %+v", other)
	}
}
