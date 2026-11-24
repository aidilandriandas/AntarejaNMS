package snmp

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/gosnmp/gosnmp"
)

// BRIDGE-MIB (RFC 4188) dan Q-BRIDGE-MIB (RFC 4363): tabel MAC yang dipelajari switch.
const (
	oidDot1dBasePortIfIndex = ".1.3.6.1.2.1.17.1.4.1.2"     // indeks: basePort → ifIndex
	oidDot1qTpFdbPort       = ".1.3.6.1.2.1.17.7.1.2.2.1.2" // indeks: fdbId.m1..m6 → basePort
	oidDot1qTpFdbStatus     = ".1.3.6.1.2.1.17.7.1.2.2.1.3" // 3 = learned
	oidDot1dTpFdbPort       = ".1.3.6.1.2.1.17.4.3.1.2"     // indeks: m1..m6 → basePort
	oidDot1dTpFdbStatus     = ".1.3.6.1.2.1.17.4.3.1.3"
	fdbLearned              = 3
	fdbMax                  = 20000
)

// FDBEntry satu MAC yang dipelajari di port switch.
type FDBEntry struct {
	IfIndex int
	VLAN    int // FDB id Q-BRIDGE; 0 untuk BRIDGE-MIB
	MAC     string
}

// FDB membaca tabel MAC: Q-BRIDGE dulu (per VLAN), BRIDGE-MIB bila kosong. Hanya entri "learned".
// Cisco IOS hanya menjawab BRIDGE-MIB untuk VLAN 1 tanpa community@vlan; port lain tidak terbaca di sana.
func (c Client) FDB(host string, cr Credentials) ([]FDBEntry, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()
	walk := walker(g)
	portIf := map[int]int{}
	_ = walk(oidDot1dBasePortIfIndex, func(idx string, v gosnmp.SnmpPDU) {
		if p, err := strconv.Atoi(idx); err == nil {
			portIf[p] = int(gosnmp.ToBigInt(v.Value).Int64())
		}
	})
	read := func(portOID, statusOID string, withVLAN bool) ([]FDBEntry, error) {
		status := map[string]int64{}
		_ = walk(statusOID, func(idx string, v gosnmp.SnmpPDU) { status[idx] = gosnmp.ToBigInt(v.Value).Int64() })
		var out []FDBEntry
		err := walk(portOID, func(idx string, v gosnmp.SnmpPDU) {
			if len(out) >= fdbMax {
				return
			}
			if st, ok := status[idx]; ok && st != fdbLearned {
				return // self, mgmt, other
			}
			parts := strings.Split(idx, ".")
			vlan := 0
			if withVLAN {
				if len(parts) != 7 {
					return
				}
				vlan, _ = strconv.Atoi(parts[0])
				parts = parts[1:]
			}
			mac, ok := macFromIdx(parts)
			port := int(gosnmp.ToBigInt(v.Value).Int64())
			if !ok || port <= 0 {
				return
			}
			ifi := port
			if m, ok := portIf[port]; ok && m > 0 {
				ifi = m
			}
			out = append(out, FDBEntry{IfIndex: ifi, VLAN: vlan, MAC: mac})
		})
		return out, err
	}
	out, err := read(oidDot1qTpFdbPort, oidDot1qTpFdbStatus, true)
	if err == nil && len(out) > 0 {
		return out, nil
	}
	return read(oidDot1dTpFdbPort, oidDot1dTpFdbStatus, false)
}

func macFromIdx(parts []string) (string, bool) {
	if len(parts) != 6 {
		return "", false
	}
	var b [6]byte
	for i, p := range parts {
		n, err := strconv.Atoi(p)
		if err != nil || n < 0 || n > 255 {
			return "", false
		}
		b[i] = byte(n)
	}
	if b[0]&1 == 1 || b == [6]byte{} { // multicast/broadcast atau kosong
		return "", false
	}
	return fmt.Sprintf("%02x:%02x:%02x:%02x:%02x:%02x", b[0], b[1], b[2], b[3], b[4], b[5]), true
}
