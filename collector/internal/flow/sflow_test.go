package flow

import (
	"net/netip"
	"testing"
)

func ethV4(vlan bool, proto byte, src, dst [4]byte, sp, dp uint16) []byte {
	h := make([]byte, 12)
	if vlan {
		h = cat(h, be16(0x8100), be16(30))
	}
	ip := make([]byte, 20)
	ip[0], ip[9] = 0x45, proto
	copy(ip[12:], src[:])
	copy(ip[16:], dst[:])
	return cat(h, be16(0x0800), ip, be16(sp), be16(dp), []byte{0, 0, 0, 0})
}

func rawRecord(frame uint32, hdr []byte) []byte {
	pad := (4 - len(hdr)%4) % 4
	body := cat(be32(1), be32(frame), be32(4), be32(uint32(len(hdr))), hdr, make([]byte, pad))
	return cat(be32(1), be32(uint32(len(body))), body)
}

func TestSFlow(t *testing.T) {
	// flow sample biasa: rate 512, input 3, output 9
	rec := rawRecord(1514, ethV4(true, 6, [4]byte{10, 0, 0, 5}, [4]byte{10, 9, 0, 1}, 50000, 554))
	fs := cat(be32(1), be32(0<<24|3), be32(512), be32(0), be32(0), be32(3), be32(9), be32(1), rec)
	// expanded flow sample IPv6 UDP 53: rate 100, input 7
	h6 := make([]byte, 40)
	h6[6] = 17
	s6 := netip.MustParseAddr("2001:db8::5").As16()
	d6 := netip.MustParseAddr("2001:db8::53").As16()
	copy(h6[8:], s6[:])
	copy(h6[24:], d6[:])
	eth6 := cat(make([]byte, 12), be16(0x86dd), h6, be16(40000), be16(53))
	efs := cat(be32(1), be32(0), be32(3), be32(100), be32(0), be32(0), be32(0), be32(7), be32(0), be32(2), be32(1), rawRecord(100, eth6))
	counter := cat(be32(1), be32(0), be32(0)) // counter sample (format 2), diabaikan
	samples := cat(be32(1), be32(uint32(len(fs))), fs, be32(3), be32(uint32(len(efs))), efs, be32(2), be32(uint32(len(counter))), counter)
	dg := cat(be32(5), be32(1), []byte{10, 0, 0, 254}, be32(0), be32(1), be32(1000), be32(3), samples)
	if !IsSFlow(dg) {
		t.Fatal("dikenali sebagai sFlow")
	}
	rs, err := NewDecoder().Decode(exp, dg)
	if err != nil || len(rs) != 2 {
		t.Fatalf("%v %v", rs, err)
	}
	if r := rs[0]; r.Src.String() != "10.0.0.5" || r.DstPort != 554 || r.Bytes != 1514*512 || r.Packets != 512 || r.InIf != 3 || r.OutIf != 9 || r.Proto != 6 {
		t.Fatalf("sample IPv4 ber-VLAN: %+v", r)
	}
	if r := rs[1]; r.Dst.String() != "2001:db8::53" || r.DstPort != 53 || r.Bytes != 10000 || r.InIf != 7 || r.OutIf != 2 {
		t.Fatalf("expanded IPv6: %+v", r)
	}
	// NetFlow v5 tidak salah dikenali sebagai sFlow
	if IsSFlow(cat(be16(5), be16(1), be32(0))) {
		t.Fatal("NetFlow v5 bukan sFlow")
	}
}
