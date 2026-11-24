// Package flow menerima NetFlow v5, NetFlow v9 (RFC 3954), IPFIX (RFC 7011), dan sFlow v5 dari router/switch, lalu
// meringkasnya per 5 menit: siapa (IP sumber/tujuan) dan aplikasi apa (protokol + port layanan) yang memakai
// bandwidth di tiap port. Ditulis dari spesifikasi publik; hanya menerima dari IP perangkat yang terdaftar.
package flow

import (
	"encoding/binary"
	"errors"
	"net/netip"
	"sync"
)

// Record satu flow hasil dekode (byte & paket sudah dikali laju sampling bila diketahui).
type Record struct {
	Src, Dst         netip.Addr
	Proto            uint8
	SrcPort, DstPort uint16
	InIf, OutIf      uint32
	Bytes, Packets   uint64
}

var errShort = errors.New("paket flow terpotong")

// Nomor elemen informasi (sama untuk NetFlow v9 dan IPFIX).
const (
	ieOctets      = 1
	iePackets     = 2
	ieProto       = 4
	ieSrcPort     = 7
	ieSrcV4       = 8
	ieInIf        = 10
	ieDstPort     = 11
	ieDstV4       = 12
	ieOutIf       = 14
	ieOutOctets   = 23
	ieOutPackets  = 24
	ieSrcV6       = 27
	ieDstV6       = 28
	ieSampling    = 34
	ieSamplingPkt = 305
)

type field struct {
	id, length uint16
	enterprise bool
}

type template struct {
	fields  []field
	options bool // template opsi (mis. laju sampling)
}

// Decoder menyimpan template per eksportir (alamat + source ID/domain + ID template).
type Decoder struct {
	mu        sync.Mutex
	templates map[tkey]template
	sampling  map[skey]uint64 // laju sampling dari record opsi, per eksportir+domain
}

type tkey struct {
	exporter netip.Addr
	domain   uint32
	id       uint16
	version  uint16
}

type skey struct {
	exporter netip.Addr
	domain   uint32
}

func NewDecoder() *Decoder {
	return &Decoder{templates: map[tkey]template{}, sampling: map[skey]uint64{}}
}

// Decode satu datagram dari eksportir.
func (d *Decoder) Decode(exporter netip.Addr, b []byte) ([]Record, error) {
	if len(b) < 2 {
		return nil, errShort
	}
	if IsSFlow(b) {
		return decodeSFlow(b)
	}
	switch binary.BigEndian.Uint16(b) {
	case 5:
		return decodeV5(b)
	case 9:
		return d.decodeTemplated(exporter, b, 9)
	case 10:
		return d.decodeTemplated(exporter, b, 10)
	}
	return nil, errors.New("versi flow tidak didukung (hanya NetFlow v5, v9, IPFIX, sFlow v5)")
}

func decodeV5(b []byte) ([]Record, error) {
	if len(b) < 24 {
		return nil, errShort
	}
	count := int(binary.BigEndian.Uint16(b[2:]))
	sampling := uint64(binary.BigEndian.Uint16(b[22:]) & 0x3fff)
	if sampling == 0 {
		sampling = 1
	}
	if count > 30 || len(b) < 24+count*48 {
		return nil, errShort
	}
	out := make([]Record, 0, count)
	for i := 0; i < count; i++ {
		r := b[24+i*48:]
		src, _ := netip.AddrFromSlice(r[0:4])
		dst, _ := netip.AddrFromSlice(r[4:8])
		out = append(out, Record{Src: src, Dst: dst, InIf: uint32(binary.BigEndian.Uint16(r[12:])), OutIf: uint32(binary.BigEndian.Uint16(r[14:])),
			Packets: uint64(binary.BigEndian.Uint32(r[16:])) * sampling, Bytes: uint64(binary.BigEndian.Uint32(r[20:])) * sampling,
			SrcPort: binary.BigEndian.Uint16(r[32:]), DstPort: binary.BigEndian.Uint16(r[34:]), Proto: r[38]})
	}
	return out, nil
}

func (d *Decoder) decodeTemplated(exporter netip.Addr, b []byte, version uint16) ([]Record, error) {
	var domain uint32
	var pos, end int
	if version == 9 {
		if len(b) < 20 {
			return nil, errShort
		}
		domain, pos, end = binary.BigEndian.Uint32(b[16:]), 20, len(b)
	} else {
		if len(b) < 16 {
			return nil, errShort
		}
		end = int(binary.BigEndian.Uint16(b[2:]))
		if end > len(b) || end < 16 {
			return nil, errShort
		}
		domain, pos = binary.BigEndian.Uint32(b[12:]), 16
	}
	var out []Record
	for pos+4 <= end {
		setID := binary.BigEndian.Uint16(b[pos:])
		setLen := int(binary.BigEndian.Uint16(b[pos+2:]))
		if setLen < 4 || pos+setLen > end {
			return out, errShort
		}
		body := b[pos+4 : pos+setLen]
		pos += setLen
		switch {
		case (version == 9 && setID == 0) || (version == 10 && setID == 2):
			d.readTemplates(exporter, domain, version, body, false)
		case (version == 9 && setID == 1) || (version == 10 && setID == 3):
			d.readTemplates(exporter, domain, version, body, true)
		case setID >= 256:
			d.mu.Lock()
			t, ok := d.templates[tkey{exporter, domain, setID, version}]
			samp := d.sampling[skey{exporter, domain}]
			d.mu.Unlock()
			if !ok {
				continue // template belum diterima; data ini dilewati
			}
			recs, rate := readData(body, t)
			if t.options {
				if rate > 0 {
					d.mu.Lock()
					d.sampling[skey{exporter, domain}] = rate
					d.mu.Unlock()
				}
				continue
			}
			for _, r := range recs {
				if samp > 1 {
					r.Bytes *= samp
					r.Packets *= samp
				}
				out = append(out, r)
			}
		}
	}
	return out, nil
}

func (d *Decoder) readTemplates(exporter netip.Addr, domain uint32, version uint16, body []byte, options bool) {
	p := 0
	for p+4 <= len(body) {
		id := binary.BigEndian.Uint16(body[p:])
		var fields []field
		if version == 9 && options {
			if p+6 > len(body) {
				return
			}
			scopeLen, optLen := int(binary.BigEndian.Uint16(body[p+2:])), int(binary.BigEndian.Uint16(body[p+4:]))
			p += 6
			n := (scopeLen + optLen) / 4
			if p+n*4 > len(body) {
				return
			}
			for i := 0; i < n; i++ {
				fields = append(fields, field{id: binary.BigEndian.Uint16(body[p:]), length: binary.BigEndian.Uint16(body[p+2:])})
				p += 4
			}
		} else {
			count := int(binary.BigEndian.Uint16(body[p+2:]))
			p += 4
			if version == 10 && options {
				p += 2 // jumlah field scope
			}
			if id < 256 || count == 0 {
				return // padding atau penarikan template
			}
			for i := 0; i < count; i++ {
				if p+4 > len(body) {
					return
				}
				f := field{id: binary.BigEndian.Uint16(body[p:]), length: binary.BigEndian.Uint16(body[p+2:])}
				p += 4
				if version == 10 && f.id&0x8000 != 0 {
					f.enterprise, f.id = true, f.id&0x7fff
					p += 4 // nomor enterprise
				}
				fields = append(fields, f)
			}
		}
		if id >= 256 && len(fields) > 0 && len(fields) <= 128 {
			d.mu.Lock()
			if len(d.templates) < 10000 {
				d.templates[tkey{exporter, domain, id, version}] = template{fields: fields, options: options}
			}
			d.mu.Unlock()
		}
	}
}

func uintN(b []byte) uint64 {
	var v uint64
	for _, x := range b {
		v = v<<8 | uint64(x)
	}
	return v
}

// readData membaca semua record data dalam satu set. rate = laju sampling bila record berisi elemen sampling.
func readData(body []byte, t template) ([]Record, uint64) {
	var out []Record
	var rate uint64
	p := 0
	for {
		var r Record
		var outBytes, outPkts uint64
		start := p
		ok := true
		for _, f := range t.fields {
			l := int(f.length)
			if l == 0xffff { // IPFIX panjang variabel
				if p >= len(body) {
					ok = false
					break
				}
				l = int(body[p])
				p++
				if l == 255 {
					if p+2 > len(body) {
						ok = false
						break
					}
					l = int(binary.BigEndian.Uint16(body[p:]))
					p += 2
				}
			}
			if p+l > len(body) {
				ok = false
				break
			}
			v := body[p : p+l]
			p += l
			if f.enterprise || l > 16 {
				continue
			}
			switch f.id {
			case ieOctets:
				r.Bytes = uintN(v)
			case iePackets:
				r.Packets = uintN(v)
			case ieOutOctets:
				outBytes = uintN(v)
			case ieOutPackets:
				outPkts = uintN(v)
			case ieProto:
				r.Proto = uint8(uintN(v))
			case ieSrcPort:
				r.SrcPort = uint16(uintN(v))
			case ieDstPort:
				r.DstPort = uint16(uintN(v))
			case ieInIf:
				r.InIf = uint32(uintN(v))
			case ieOutIf:
				r.OutIf = uint32(uintN(v))
			case ieSrcV4, ieSrcV6:
				if a, ok := netip.AddrFromSlice(v); ok {
					r.Src = a
				}
			case ieDstV4, ieDstV6:
				if a, ok := netip.AddrFromSlice(v); ok {
					r.Dst = a
				}
			case ieSampling, ieSamplingPkt:
				rate = uintN(v)
			}
		}
		if !ok || p == start {
			break
		}
		if r.Bytes == 0 && outBytes > 0 {
			r.Bytes, r.Packets = outBytes, outPkts
		}
		if r.Src.IsValid() && r.Dst.IsValid() && r.Bytes > 0 {
			out = append(out, r)
		}
		if len(body)-p < 4 { // sisa = padding
			break
		}
	}
	return out, rate
}
