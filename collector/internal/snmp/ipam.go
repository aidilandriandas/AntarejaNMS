package snmp

import (
	"fmt"
	"net"
	"strconv"
	"strings"

	"github.com/gosnmp/gosnmp"
)

// IP-MIB (RFC 4293 / RFC 1213): ipAddrTable (alamat interface) dan ipNetToMediaTable (ARP IPv4).
const (
	oidIPAdEntIfIndex = ".1.3.6.1.2.1.4.20.1.2"
	oidIPAdEntNetMask = ".1.3.6.1.2.1.4.20.1.3"
	oidIPNetToMediaPA = ".1.3.6.1.2.1.4.22.1.2" // indeks: ifIndex.a.b.c.d, nilai: MAC
	oidIPNetToMediaTy = ".1.3.6.1.2.1.4.22.1.4" // 2=invalid, 3=dynamic, 4=static
)

type IfAddr struct {
	IP      string
	IfIndex int
	Prefix  int
}

type ARPEntry struct {
	IfIndex int
	IP, MAC string
}

func ipFromIdx(parts []string) string {
	if len(parts) != 4 {
		return ""
	}
	ip := net.ParseIP(strings.Join(parts, "."))
	if ip == nil || ip.To4() == nil {
		return ""
	}
	return ip.String()
}

// Addresses membaca alamat IPv4 milik interface perangkat.
func (c Client) Addresses(host string, cr Credentials) ([]IfAddr, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()
	walk := walker(g)
	ifIdx, masks := map[string]int{}, map[string]int{}
	if err := walk(oidIPAdEntIfIndex, func(idx string, v gosnmp.SnmpPDU) {
		ifIdx[idx] = int(gosnmp.ToBigInt(v.Value).Int64())
	}); err != nil {
		return nil, err
	}
	_ = walk(oidIPAdEntNetMask, func(idx string, v gosnmp.SnmpPDU) {
		if s, ok := v.Value.(string); ok {
			if m := net.ParseIP(s).To4(); m != nil {
				ones, _ := net.IPMask(m).Size()
				masks[idx] = ones
			}
		}
	})
	var out []IfAddr
	for idx, i := range ifIdx {
		if ip := ipFromIdx(strings.Split(idx, ".")); ip != "" {
			out = append(out, IfAddr{IP: ip, IfIndex: i, Prefix: masks[idx]})
		}
	}
	return out, nil
}

// ARP membaca tabel ARP IPv4 (entri invalid dilewati).
func (c Client) ARP(host string, cr Credentials) ([]ARPEntry, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()
	walk := walker(g)
	invalid := map[string]bool{}
	_ = walk(oidIPNetToMediaTy, func(idx string, v gosnmp.SnmpPDU) {
		if gosnmp.ToBigInt(v.Value).Int64() == 2 {
			invalid[idx] = true
		}
	})
	var out []ARPEntry
	err = walk(oidIPNetToMediaPA, func(idx string, v gosnmp.SnmpPDU) {
		if invalid[idx] {
			return
		}
		parts := strings.Split(idx, ".")
		if len(parts) != 5 {
			return
		}
		b, ok := v.Value.([]byte)
		if !ok || len(b) != 6 || (b[0]|b[1]|b[2]|b[3]|b[4]|b[5]) == 0 || (b[0]&b[1]&b[2]&b[3]&b[4]&b[5]) == 0xff {
			return // MAC kosong / broadcast
		}
		ifi, _ := strconv.Atoi(parts[0])
		if ip := ipFromIdx(parts[1:]); ip != "" {
			out = append(out, ARPEntry{IfIndex: ifi, IP: ip, MAC: fmt.Sprintf("%02x:%02x:%02x:%02x:%02x:%02x", b[0], b[1], b[2], b[3], b[4], b[5])})
		}
	})
	return out, err
}

func walker(g *gosnmp.GoSNMP) func(base string, fn func(idx string, v gosnmp.SnmpPDU)) error {
	return func(base string, fn func(idx string, v gosnmp.SnmpPDU)) error {
		cb := func(v gosnmp.SnmpPDU) error {
			if !missing(v) && strings.HasPrefix(v.Name, base+".") {
				fn(strings.TrimPrefix(v.Name, base+"."), v)
			}
			return nil
		}
		if g.Version == gosnmp.Version1 {
			return g.Walk(base, cb)
		}
		return g.BulkWalk(base, cb)
	}
}
