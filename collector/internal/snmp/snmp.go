// Package snmp membaca data perangkat lewat SNMP (GET/GETBULK saja — tidak pernah SET).
//
// OID yang dipakai berasal dari MIB standar: SNMPv2-MIB (RFC 3418) dan IF-MIB (RFC 2863).
package snmp

import (
	"errors"
	"fmt"
	"math/big"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/gosnmp/gosnmp"
)

type Credentials struct {
	Version   string // "1", "2c", "3"
	Port      int
	Community string
	// SNMPv3
	User      string
	Level     string // noAuthNoPriv | authNoPriv | authPriv
	AuthProto string
	AuthPass  string
	PrivProto string
	PrivPass  string
}

type Client struct {
	Timeout time.Duration
	Retries int
}

// SNMPv2-MIB::system
const (
	oidSysDescr    = ".1.3.6.1.2.1.1.1.0"
	oidSysObjectID = ".1.3.6.1.2.1.1.2.0"
	oidSysUpTime   = ".1.3.6.1.2.1.1.3.0"
	oidSysName     = ".1.3.6.1.2.1.1.5.0"
	oidSysLocation = ".1.3.6.1.2.1.1.6.0"
)

type System struct {
	Descr, ObjectID, Name, Location string
	UptimeTicks                     uint32 // seperseratus detik, wrap tiap ±497 hari
}

func (s System) UptimeSeconds() int64 { return int64(s.UptimeTicks) / 100 }

func (c Client) connect(host string, cr Credentials) (*gosnmp.GoSNMP, error) {
	g := &gosnmp.GoSNMP{
		Target:         host,
		Port:           uint16(cr.Port),
		Timeout:        c.Timeout,
		Retries:        c.Retries,
		MaxRepetitions: 25,
		MaxOids:        gosnmp.MaxOids,
		Transport:      "udp",
	}
	if g.Port == 0 {
		g.Port = 161
	}
	switch cr.Version {
	case "1":
		g.Version, g.Community = gosnmp.Version1, cr.Community
	case "2c", "":
		g.Version, g.Community = gosnmp.Version2c, cr.Community
	case "3":
		g.Version = gosnmp.Version3
		g.SecurityModel = gosnmp.UserSecurityModel
		usm := &gosnmp.UsmSecurityParameters{UserName: cr.User}
		switch cr.Level {
		case "authPriv":
			g.MsgFlags = gosnmp.AuthPriv
		case "authNoPriv":
			g.MsgFlags = gosnmp.AuthNoPriv
		default:
			g.MsgFlags = gosnmp.NoAuthNoPriv
		}
		if g.MsgFlags&gosnmp.AuthNoPriv != 0 {
			p, ok := authProtos[strings.ToUpper(cr.AuthProto)]
			if !ok {
				return nil, fmt.Errorf("protokol auth SNMPv3 tidak dikenal: %q", cr.AuthProto)
			}
			usm.AuthenticationProtocol, usm.AuthenticationPassphrase = p, cr.AuthPass
		}
		if g.MsgFlags == gosnmp.AuthPriv {
			p, ok := privProtos[strings.ToUpper(cr.PrivProto)]
			if !ok {
				return nil, fmt.Errorf("protokol privacy SNMPv3 tidak dikenal: %q", cr.PrivProto)
			}
			usm.PrivacyProtocol, usm.PrivacyPassphrase = p, cr.PrivPass
		}
		g.SecurityParameters = usm
	default:
		return nil, fmt.Errorf("versi SNMP tidak dikenal: %q", cr.Version)
	}
	if err := g.Connect(); err != nil {
		return nil, fmt.Errorf("tidak bisa membuka koneksi SNMP: %w", err)
	}
	return g, nil
}

var authProtos = map[string]gosnmp.SnmpV3AuthProtocol{
	"MD5": gosnmp.MD5, "SHA": gosnmp.SHA, "SHA224": gosnmp.SHA224, "SHA256": gosnmp.SHA256, "SHA384": gosnmp.SHA384, "SHA512": gosnmp.SHA512,
}
var privProtos = map[string]gosnmp.SnmpV3PrivProtocol{
	"DES": gosnmp.DES, "AES": gosnmp.AES, "AES192": gosnmp.AES192, "AES256": gosnmp.AES256,
}

// Friendly menerjemahkan galat gosnmp menjadi pesan yang bisa ditindaklanjuti engineer.
func Friendly(err error) string {
	if err == nil {
		return ""
	}
	m := err.Error()
	switch {
	case strings.Contains(m, "timeout") || strings.Contains(m, "i/o timeout"):
		return "tidak ada jawaban SNMP (timeout): cek community/user, ACL SNMP di perangkat, dan UDP 161 dari server"
	case strings.Contains(m, "connection refused"):
		return "perangkat menolak koneksi SNMP (UDP 161 tertutup): agen SNMP belum aktif atau port SNMP berbeda"
	case strings.Contains(m, "no route to host") || strings.Contains(m, "network is unreachable") || strings.Contains(m, "host is down"):
		return "perangkat tidak terjangkau dari server (tidak ada rute)"
	case strings.Contains(m, "no such host"):
		return "nama host tidak bisa di-resolve (DNS)"
	case strings.Contains(m, "unknown user") || strings.Contains(m, "UnknownUserName"):
		return "user SNMPv3 tidak dikenal perangkat"
	case strings.Contains(m, "wrong digest") || strings.Contains(m, "WrongDigests") || strings.Contains(m, "authentication"):
		return "password/protokol auth SNMPv3 salah"
	case strings.Contains(m, "decryption"):
		return "password/protokol privacy SNMPv3 salah"
	}
	if len(m) > 200 {
		m = m[:200]
	}
	return m
}

// System membaca grup system. Galat bila perangkat tidak menjawab.
func (c Client) System(host string, cr Credentials) (System, error) {
	var s System
	g, err := c.connect(host, cr)
	if err != nil {
		return s, err
	}
	defer g.Conn.Close()
	pkt, err := g.Get([]string{oidSysDescr, oidSysObjectID, oidSysUpTime, oidSysName, oidSysLocation})
	if err != nil {
		return s, err
	}
	if pkt.Error != gosnmp.NoError {
		return s, fmt.Errorf("perangkat menjawab galat SNMP: %s", pkt.Error)
	}
	got := 0
	for _, v := range pkt.Variables {
		if missing(v) {
			continue
		}
		got++
		switch v.Name {
		case oidSysDescr:
			s.Descr = text(v.Value)
		case oidSysObjectID:
			s.ObjectID = text(v.Value)
		case oidSysUpTime:
			s.UptimeTicks = uint32(gosnmp.ToBigInt(v.Value).Uint64())
		case oidSysName:
			s.Name = text(v.Value)
		case oidSysLocation:
			s.Location = text(v.Value)
		}
	}
	if got == 0 {
		return s, errors.New("perangkat menjawab tetapi grup system tidak tersedia (cek view SNMP)")
	}
	return s, nil
}

type Interface struct {
	Index                   int
	Name, Alias, Descr, MAC string
	Type                    int
	SpeedBps                uint64
	AdminUp, OperUp         bool
	LastChangeTicks         uint32
	InOctets, OutOctets     *big.Int // nil bila tidak tersedia
	InErrors, OutErrors     *big.Int
	HC                      bool // counter 64-bit
	// Paket & discard (nil bila tidak tersedia). Unicast/multicast/broadcast memakai counter 64-bit bila ada.
	InUcast, OutUcast, InMcast, OutMcast, InBcast, OutBcast *big.Int
	InDiscards, OutDiscards                                 *big.Int
	pktHC                                                   map[string]bool
}

// IF-MIB: ifTable (.2.2.1.x) dan ifXTable (.31.1.1.1.x)
var ifColumns = map[string]string{
	"descr": ".1.3.6.1.2.1.2.2.1.2", "type": ".1.3.6.1.2.1.2.2.1.3", "speed": ".1.3.6.1.2.1.2.2.1.5",
	"mac": ".1.3.6.1.2.1.2.2.1.6", "admin": ".1.3.6.1.2.1.2.2.1.7", "oper": ".1.3.6.1.2.1.2.2.1.8",
	"lastchange": ".1.3.6.1.2.1.2.2.1.9", "in": ".1.3.6.1.2.1.2.2.1.10", "inerr": ".1.3.6.1.2.1.2.2.1.14",
	"out": ".1.3.6.1.2.1.2.2.1.16", "outerr": ".1.3.6.1.2.1.2.2.1.20",
	"name": ".1.3.6.1.2.1.31.1.1.1.1", "hcin": ".1.3.6.1.2.1.31.1.1.1.6", "hcout": ".1.3.6.1.2.1.31.1.1.1.10",
	"highspeed": ".1.3.6.1.2.1.31.1.1.1.15", "alias": ".1.3.6.1.2.1.31.1.1.1.18",
	// paket: ifInUcastPkts/ifOutUcastPkts (32-bit), ifInDiscards/ifOutDiscards, ifXTable multicast/broadcast 32 & 64-bit
	"inucast": ".1.3.6.1.2.1.2.2.1.11", "outucast": ".1.3.6.1.2.1.2.2.1.17",
	"indisc": ".1.3.6.1.2.1.2.2.1.13", "outdisc": ".1.3.6.1.2.1.2.2.1.19",
	"inmcast": ".1.3.6.1.2.1.31.1.1.1.2", "inbcast": ".1.3.6.1.2.1.31.1.1.1.3",
	"outmcast": ".1.3.6.1.2.1.31.1.1.1.4", "outbcast": ".1.3.6.1.2.1.31.1.1.1.5",
	"hcinucast": ".1.3.6.1.2.1.31.1.1.1.7", "hcinmcast": ".1.3.6.1.2.1.31.1.1.1.8", "hcinbcast": ".1.3.6.1.2.1.31.1.1.1.9",
	"hcoutucast": ".1.3.6.1.2.1.31.1.1.1.11", "hcoutmcast": ".1.3.6.1.2.1.31.1.1.1.12", "hcoutbcast": ".1.3.6.1.2.1.31.1.1.1.13",
}

// pktCols: kolom paket → field (32-bit diabaikan bila versi 64-bit sudah terbaca).
var pktCols = map[string]struct {
	field string
	hc    bool
}{
	"inucast": {"InUcast", false}, "outucast": {"OutUcast", false}, "inmcast": {"InMcast", false}, "inbcast": {"InBcast", false},
	"outmcast": {"OutMcast", false}, "outbcast": {"OutBcast", false},
	"hcinucast": {"InUcast", true}, "hcoutucast": {"OutUcast", true}, "hcinmcast": {"InMcast", true}, "hcinbcast": {"InBcast", true},
	"hcoutmcast": {"OutMcast", true}, "hcoutbcast": {"OutBcast", true},
}

func (it *Interface) setPkt(field string, v *big.Int, hc bool) {
	if it.pktHC == nil {
		it.pktHC = map[string]bool{}
	}
	if !hc && it.pktHC[field] {
		return
	}
	if hc {
		it.pktHC[field] = true
	}
	switch field {
	case "InUcast":
		it.InUcast = v
	case "OutUcast":
		it.OutUcast = v
	case "InMcast":
		it.InMcast = v
	case "OutMcast":
		it.OutMcast = v
	case "InBcast":
		it.InBcast = v
	case "OutBcast":
		it.OutBcast = v
	}
}

var walkOrder = []string{"descr", "type", "speed", "mac", "admin", "oper", "lastchange", "in", "inerr", "out", "outerr", "name", "hcin", "hcout", "highspeed", "alias",
	"inucast", "outucast", "indisc", "outdisc", "hcinucast", "hcoutucast", "hcinmcast", "hcoutmcast", "hcinbcast", "hcoutbcast", "inmcast", "outmcast", "inbcast", "outbcast"}

// Interfaces membaca semua port. Kolom yang tidak didukung perangkat dilewati (mis. ifXTable di SNMPv1).
func (c Client) Interfaces(host string, cr Credentials) ([]Interface, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()
	byIdx := map[int]*Interface{}
	var order []int
	get := func(idx int) *Interface {
		if it, ok := byIdx[idx]; ok {
			return it
		}
		it := &Interface{Index: idx}
		byIdx[idx] = it
		order = append(order, idx)
		return it
	}
	for _, col := range walkOrder {
		if cr.Version == "1" && strings.HasPrefix(col, "hc") {
			continue // SNMPv1 tidak mendukung Counter64
		}
		base := ifColumns[col]
		fn := func(v gosnmp.SnmpPDU) error {
			if missing(v) {
				return nil
			}
			idx, err := strconv.Atoi(v.Name[strings.LastIndex(v.Name, ".")+1:])
			if err != nil || !strings.HasPrefix(v.Name, base+".") {
				return nil
			}
			it := get(idx)
			switch col {
			case "descr":
				it.Descr = text(v.Value)
			case "name":
				it.Name = text(v.Value)
			case "alias":
				it.Alias = text(v.Value)
			case "type":
				it.Type = int(gosnmp.ToBigInt(v.Value).Int64())
			case "speed":
				if it.SpeedBps == 0 {
					it.SpeedBps = gosnmp.ToBigInt(v.Value).Uint64()
				}
			case "highspeed":
				if hs := gosnmp.ToBigInt(v.Value).Uint64(); hs > 0 {
					it.SpeedBps = hs * 1_000_000
				}
			case "mac":
				it.MAC = mac(v.Value)
			case "admin":
				it.AdminUp = gosnmp.ToBigInt(v.Value).Int64() == 1
			case "oper":
				it.OperUp = gosnmp.ToBigInt(v.Value).Int64() == 1
			case "lastchange":
				it.LastChangeTicks = uint32(gosnmp.ToBigInt(v.Value).Uint64())
			case "in":
				if !it.HC {
					it.InOctets = gosnmp.ToBigInt(v.Value)
				}
			case "out":
				if !it.HC {
					it.OutOctets = gosnmp.ToBigInt(v.Value)
				}
			case "hcin":
				it.InOctets, it.HC = gosnmp.ToBigInt(v.Value), true
			case "hcout":
				it.OutOctets, it.HC = gosnmp.ToBigInt(v.Value), true
			case "inerr":
				it.InErrors = gosnmp.ToBigInt(v.Value)
			case "outerr":
				it.OutErrors = gosnmp.ToBigInt(v.Value)
			case "indisc":
				it.InDiscards = gosnmp.ToBigInt(v.Value)
			case "outdisc":
				it.OutDiscards = gosnmp.ToBigInt(v.Value)
			default:
				if pc, ok := pktCols[col]; ok {
					it.setPkt(pc.field, gosnmp.ToBigInt(v.Value), pc.hc)
				}
			}
			return nil
		}
		var werr error
		if g.Version == gosnmp.Version1 {
			werr = g.Walk(base, fn)
		} else {
			werr = g.BulkWalk(base, fn)
		}
		if werr != nil {
			if col == "descr" {
				return nil, werr // kolom wajib; perangkat tidak menjawab
			}
			continue
		}
	}
	out := make([]Interface, 0, len(order))
	for _, idx := range order {
		it := byIdx[idx]
		if it.Name == "" {
			it.Name = it.Descr
		}
		out = append(out, *it)
	}
	return out, nil
}

func missing(v gosnmp.SnmpPDU) bool {
	return v.Type == gosnmp.NoSuchObject || v.Type == gosnmp.NoSuchInstance || v.Type == gosnmp.EndOfMibView || v.Type == gosnmp.Null
}

func text(v any) string {
	var s string
	switch x := v.(type) {
	case []byte:
		s = string(x)
	case string:
		s = x
	default:
		s = fmt.Sprint(x)
	}
	s = strings.TrimRight(s, "\x00")
	if !utf8.ValidString(s) {
		s = strings.ToValidUTF8(s, "?")
	}
	return strings.TrimSpace(s)
}

func mac(v any) string {
	b, ok := v.([]byte)
	if !ok || len(b) != 6 {
		return ""
	}
	return fmt.Sprintf("%02x:%02x:%02x:%02x:%02x:%02x", b[0], b[1], b[2], b[3], b[4], b[5])
}

// Rebooted menilai apakah perangkat restart di antara dua pengamatan sysUpTime.
// elapsed = selang waktu nyata antar pengamatan. Wrap 32-bit (±497 hari) tidak dianggap reboot.
func Rebooted(prevTicks, nowTicks uint32, elapsed time.Duration) bool {
	if nowTicks >= prevTicks {
		return false
	}
	expected := uint64(prevTicks) + uint64(elapsed/(10*time.Millisecond))
	const wrap = uint64(1) << 32
	if expected >= wrap {
		// counter memang sudah melewati batas: nilai baru seharusnya ≈ expected - 2^32
		diff := int64(nowTicks) - int64(expected-wrap)
		if diff < 0 {
			diff = -diff
		}
		return diff > 60*100 // selisih > 60 detik dari perkiraan → reboot
	}
	return true
}

// Session adalah koneksi SNMP yang dipakai ulang untuk beberapa GET/WALK (profil perangkat).
type Session struct {
	g       *gosnmp.GoSNMP
	version string
}

func (c Client) Session(host string, cr Credentials) (*Session, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	return &Session{g: g, version: cr.Version}, nil
}

func (s *Session) Close() { s.g.Conn.Close() }

// Get membaca beberapa OID skalar. Hasil: OID lengkap (berawalan titik) → nilai; OID yang tidak ada dilewati.
func (s *Session) Get(oids []string) (map[string]any, error) {
	out := map[string]any{}
	for start := 0; start < len(oids); start += 20 {
		end := min(start+20, len(oids))
		pkt, err := s.g.Get(oids[start:end])
		if err != nil {
			return out, err
		}
		for _, v := range pkt.Variables {
			if !missing(v) {
				out[v.Name] = pduValue(v)
			}
		}
	}
	return out, nil
}

// Walk membaca satu kolom tabel. Hasil: sufiks indeks (mis. "3" atau "0.2.1") → nilai.
func (s *Session) Walk(base string) (map[string]any, error) {
	out := map[string]any{}
	fn := func(v gosnmp.SnmpPDU) error {
		if !missing(v) && strings.HasPrefix(v.Name, base+".") {
			out[strings.TrimPrefix(v.Name, base+".")] = pduValue(v)
		}
		return nil
	}
	var err error
	if s.version == "1" {
		err = s.g.Walk(base, fn)
	} else {
		err = s.g.BulkWalk(base, fn)
	}
	return out, err
}

// pduValue mengubah nilai SNMP menjadi float64 (angka) atau string (teks/OID/MAC).
func pduValue(v gosnmp.SnmpPDU) any {
	switch v.Type {
	case gosnmp.OctetString:
		b, _ := v.Value.([]byte)
		return text(b)
	case gosnmp.ObjectIdentifier, gosnmp.IPAddress:
		return text(v.Value)
	default:
		f, _ := new(big.Float).SetInt(gosnmp.ToBigInt(v.Value)).Float64()
		return f
	}
}
