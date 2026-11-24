package flow

import (
	"encoding/binary"
	"errors"
	"net/netip"
)

// sFlow v5 (sflow.org, "sFlow Version 5"): hanya flow sample (format 1 dan 3) dengan record raw packet header
// (format 1, Ethernet). Byte = panjang frame x laju sampling, paket = laju sampling. Counter sample diabaikan.

// IsSFlow: datagram sFlow diawali versi 32-bit = 5 (NetFlow v5 diawali versi 16-bit lalu jumlah record).
func IsSFlow(b []byte) bool {
	return len(b) >= 4 && binary.BigEndian.Uint32(b) == 5
}

func decodeSFlow(b []byte) ([]Record, error) {
	if len(b) < 28 {
		return nil, errShort
	}
	p := 4
	switch binary.BigEndian.Uint32(b[p:]) {
	case 1:
		p += 4 + 4
	case 2:
		p += 4 + 16
	default:
		return nil, errors.New("alamat agen sFlow tidak dikenal")
	}
	if p+16 > len(b) {
		return nil, errShort
	}
	p += 12 // sub agent, sequence, uptime
	n := int(binary.BigEndian.Uint32(b[p:]))
	p += 4
	var out []Record
	for i := 0; i < n && p+8 <= len(b); i++ {
		format := binary.BigEndian.Uint32(b[p:])
		l := int(binary.BigEndian.Uint32(b[p+4:]))
		p += 8
		if l < 0 || p+l > len(b) {
			return out, errShort
		}
		s := b[p : p+l]
		p += l
		if format>>12 != 0 { // hanya enterprise standar (0)
			continue
		}
		switch format & 0xfff {
		case 1:
			out = append(out, flowSample(s, false)...)
		case 3:
			out = append(out, flowSample(s, true)...)
		}
	}
	return out, nil
}

func flowSample(s []byte, expanded bool) []Record {
	var rate, in, outIf uint32
	q := 0
	if expanded {
		if len(s) < 44 {
			return nil
		}
		rate = binary.BigEndian.Uint32(s[12:])
		if binary.BigEndian.Uint32(s[24:]) == 0 {
			in = binary.BigEndian.Uint32(s[28:])
		}
		if binary.BigEndian.Uint32(s[32:]) == 0 {
			outIf = binary.BigEndian.Uint32(s[36:])
		}
		q = 40
	} else {
		if len(s) < 32 {
			return nil
		}
		rate = binary.BigEndian.Uint32(s[8:])
		iv, ov := binary.BigEndian.Uint32(s[20:]), binary.BigEndian.Uint32(s[24:])
		if iv>>30 == 0 {
			in = iv & 0x3fffffff
		}
		if ov>>30 == 0 {
			outIf = ov & 0x3fffffff
		}
		q = 28
	}
	if rate == 0 {
		rate = 1
	}
	nrec := int(binary.BigEndian.Uint32(s[q:]))
	q += 4
	var out []Record
	for i := 0; i < nrec && q+8 <= len(s); i++ {
		format := binary.BigEndian.Uint32(s[q:])
		l := int(binary.BigEndian.Uint32(s[q+4:]))
		q += 8
		if l < 0 || q+l > len(s) {
			break
		}
		rec := s[q : q+l]
		q += l
		if format != 1 || len(rec) < 16 || binary.BigEndian.Uint32(rec) != 1 { // raw header, Ethernet
			continue
		}
		frame := binary.BigEndian.Uint32(rec[4:])
		hl := int(binary.BigEndian.Uint32(rec[12:]))
		if 16+hl > len(rec) {
			continue
		}
		r, ok := parseEthernet(rec[16 : 16+hl])
		if !ok {
			continue
		}
		r.InIf, r.OutIf = in, outIf
		r.Bytes, r.Packets = uint64(frame)*uint64(rate), uint64(rate)
		out = append(out, r)
	}
	return out
}

func parseEthernet(h []byte) (Record, bool) {
	var r Record
	if len(h) < 14 {
		return r, false
	}
	et := binary.BigEndian.Uint16(h[12:])
	p := 14
	for (et == 0x8100 || et == 0x88a8) && len(h) >= p+4 { // VLAN tag
		et = binary.BigEndian.Uint16(h[p+2:])
		p += 4
	}
	var l4 []byte
	switch et {
	case 0x0800:
		if len(h) < p+20 {
			return r, false
		}
		ihl := int(h[p]&0x0f) * 4
		r.Proto = h[p+9]
		r.Src, _ = netip.AddrFromSlice(h[p+12 : p+16])
		r.Dst, _ = netip.AddrFromSlice(h[p+16 : p+20])
		if binary.BigEndian.Uint16(h[p+6:])&0x1fff == 0 && len(h) >= p+ihl { // bukan fragmen lanjutan
			l4 = h[p+ihl:]
		}
	case 0x86dd:
		if len(h) < p+40 {
			return r, false
		}
		r.Proto = h[p+6]
		r.Src, _ = netip.AddrFromSlice(h[p+8 : p+24])
		r.Dst, _ = netip.AddrFromSlice(h[p+24 : p+40])
		l4 = h[p+40:]
	default:
		return r, false
	}
	if (r.Proto == 6 || r.Proto == 17) && len(l4) >= 4 {
		r.SrcPort, r.DstPort = binary.BigEndian.Uint16(l4), binary.BigEndian.Uint16(l4[2:])
	}
	return r, r.Src.IsValid() && r.Dst.IsValid()
}
