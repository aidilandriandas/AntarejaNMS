package snmp

import (
	"fmt"
	"net"
	"strconv"
	"strings"

	"github.com/gosnmp/gosnmp"
)

// Neighbor adalah perangkat tetangga yang terlihat lewat LLDP atau CDP.
type Neighbor struct {
	Protocol       string // "lldp" atau "cdp"
	LocalIfIndex   int    // 0 bila port lokal tidak bisa dipetakan ke ifIndex
	LocalPortName  string
	RemoteName     string // sysName / Device-ID tetangga
	RemotePort     string // port tetangga (nama atau deskripsi)
	RemoteChassis  string // MAC chassis (LLDP subtipe macAddress), format aa:bb:..
	RemoteIP       string // alamat manajemen tetangga (bila diumumkan)
	RemotePlatform string
}

// LLDP-MIB (IEEE 802.1AB-2005), akar 1.0.8802.1.1.2
const (
	oidLldpLocPortID     = ".1.0.8802.1.1.2.1.3.7.1.3"
	oidLldpLocPortDesc   = ".1.0.8802.1.1.2.1.3.7.1.4"
	oidLldpRemChassisSub = ".1.0.8802.1.1.2.1.4.1.1.4"
	oidLldpRemChassisID  = ".1.0.8802.1.1.2.1.4.1.1.5"
	oidLldpRemPortIDSub  = ".1.0.8802.1.1.2.1.4.1.1.6"
	oidLldpRemPortID     = ".1.0.8802.1.1.2.1.4.1.1.7"
	oidLldpRemPortDesc   = ".1.0.8802.1.1.2.1.4.1.1.8"
	oidLldpRemSysName    = ".1.0.8802.1.1.2.1.4.1.1.9"
	oidLldpRemManAddrIf  = ".1.0.8802.1.1.2.1.4.2.1.3"
)

// CISCO-CDP-MIB cdpCacheTable, akar 1.3.6.1.4.1.9.9.23.1.2.1.1 (indeks: ifIndex.deviceIndex)
const (
	oidCdpAddrType = ".1.3.6.1.4.1.9.9.23.1.2.1.1.3"
	oidCdpAddress  = ".1.3.6.1.4.1.9.9.23.1.2.1.1.4"
	oidCdpDeviceID = ".1.3.6.1.4.1.9.9.23.1.2.1.1.6"
	oidCdpDevPort  = ".1.3.6.1.4.1.9.9.23.1.2.1.1.7"
	oidCdpPlatform = ".1.3.6.1.4.1.9.9.23.1.2.1.1.8"
)

// Neighbors membaca tabel LLDP dan CDP. ifs dipakai untuk memetakan port lokal LLDP ke ifIndex.
// Perangkat yang tidak mendukung LLDP/CDP menghasilkan daftar kosong tanpa galat.
func (c Client) Neighbors(host string, cr Credentials, ifs []Interface) ([]Neighbor, error) {
	if cr.Version == "1" {
		return nil, nil // GETBULK tidak tersedia; tabel tetangga jarang ada di perangkat SNMPv1
	}
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()

	walk := func(base string) map[string]gosnmp.SnmpPDU {
		out := map[string]gosnmp.SnmpPDU{}
		_ = g.BulkWalk(base, func(v gosnmp.SnmpPDU) error {
			if !missing(v) && strings.HasPrefix(v.Name, base+".") {
				out[strings.TrimPrefix(v.Name, base+".")] = v
			}
			return nil
		})
		return out
	}

	var out []Neighbor

	// ---- LLDP ----
	remName := walk(oidLldpRemSysName)
	remPortID := walk(oidLldpRemPortID)
	if len(remName) > 0 || len(remPortID) > 0 {
		remPortSub := walk(oidLldpRemPortIDSub)
		remPortDesc := walk(oidLldpRemPortDesc)
		remChSub := walk(oidLldpRemChassisSub)
		remCh := walk(oidLldpRemChassisID)
		locID := walk(oidLldpLocPortID)
		locDesc := walk(oidLldpLocPortDesc)
		manAddr := walk(oidLldpRemManAddrIf)

		// indeks baris rem: timeMark.localPortNum.remIndex
		rowsSeen := map[string]bool{}
		for idx := range remPortID {
			rowsSeen[idx] = true
		}
		for idx := range remName {
			rowsSeen[idx] = true
		}
		// alamat manajemen: indeks timeMark.localPortNum.remIndex.subtype.len.addr...
		ips := map[string]string{}
		for idx := range manAddr {
			parts := strings.Split(idx, ".")
			if len(parts) >= 9 && parts[3] == "1" && parts[4] == "4" { // ipv4
				key := strings.Join(parts[:3], ".")
				if _, ok := ips[key]; !ok {
					ips[key] = strings.Join(parts[5:9], ".")
				}
			}
		}
		for idx := range rowsSeen {
			parts := strings.Split(idx, ".")
			if len(parts) != 3 {
				continue
			}
			localNum, _ := strconv.Atoi(parts[1])
			n := Neighbor{Protocol: "lldp", RemoteName: text(remName[idx].Value), RemoteIP: ips[idx]}
			// Port tetangga: subtipe 3 = MAC, 5 = ifName, 7 = local; utamakan deskripsi bila ID berupa MAC
			pid := remPortID[idx]
			if sub := intVal(remPortSub[idx]); sub == 3 {
				n.RemotePort = text(remPortDesc[idx].Value)
				if n.RemotePort == "" {
					n.RemotePort = macFromAny(pid.Value)
				}
			} else {
				n.RemotePort = text(pid.Value)
				if n.RemotePort == "" {
					n.RemotePort = text(remPortDesc[idx].Value)
				}
			}
			if intVal(remChSub[idx]) == 4 { // chassis berupa MAC
				n.RemoteChassis = macFromAny(remCh[idx].Value)
			} else if n.RemoteName == "" {
				n.RemoteName = text(remCh[idx].Value)
			}
			lp := strconv.Itoa(localNum)
			n.LocalPortName = text(locID[lp].Value)
			n.LocalIfIndex = matchLocal(localNum, n.LocalPortName, text(locDesc[lp].Value), ifs)
			if n.RemoteName != "" || n.RemoteChassis != "" || n.RemoteIP != "" {
				out = append(out, n)
			}
		}
	}

	// ---- CDP ----
	cdpID := walk(oidCdpDeviceID)
	if len(cdpID) > 0 {
		addrType, addr, port, plat := walk(oidCdpAddrType), walk(oidCdpAddress), walk(oidCdpDevPort), walk(oidCdpPlatform)
		for idx, v := range cdpID {
			parts := strings.Split(idx, ".")
			if len(parts) != 2 {
				continue
			}
			ifIndex, _ := strconv.Atoi(parts[0])
			n := Neighbor{Protocol: "cdp", LocalIfIndex: ifIndex, RemoteName: text(v.Value), RemotePort: text(port[idx].Value), RemotePlatform: text(plat[idx].Value)}
			if b, ok := addr[idx].Value.([]byte); ok && intVal(addrType[idx]) == 1 && len(b) == 4 {
				n.RemoteIP = net.IP(b).String()
			}
			for _, it := range ifs {
				if it.Index == ifIndex {
					n.LocalPortName = it.Name
				}
			}
			out = append(out, n)
		}
	}
	return out, nil
}

// matchLocal memetakan nomor port lokal LLDP ke ifIndex: sama persis, atau cocok nama/deskripsi port.
func matchLocal(num int, id, desc string, ifs []Interface) int {
	for _, it := range ifs {
		if it.Index == num && (id == "" || strings.EqualFold(id, it.Name) || strings.EqualFold(id, it.Descr) || strings.EqualFold(desc, it.Descr) || strings.EqualFold(desc, it.Name)) {
			return it.Index
		}
	}
	for _, it := range ifs {
		for _, cand := range []string{id, desc} {
			if cand != "" && (strings.EqualFold(cand, it.Name) || strings.EqualFold(cand, it.Descr) || strings.EqualFold(cand, it.Alias)) {
				return it.Index
			}
		}
	}
	for _, it := range ifs {
		if it.Index == num {
			return it.Index
		}
	}
	return 0
}

func intVal(v gosnmp.SnmpPDU) int {
	if v.Value == nil {
		return 0
	}
	return int(gosnmp.ToBigInt(v.Value).Int64())
}

func macFromAny(v any) string {
	if b, ok := v.([]byte); ok && len(b) == 6 {
		return fmt.Sprintf("%02x:%02x:%02x:%02x:%02x:%02x", b[0], b[1], b[2], b[3], b[4], b[5])
	}
	return text(v)
}

// Counters membaca ifHCIn/OutOctets untuk sejumlah port (dipakai trafik live di peta & layar NOC).
func (c Client) Counters(host string, cr Credentials, ifIndexes []int) (map[int][2]uint64, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()
	inBase, outBase := ".1.3.6.1.2.1.31.1.1.1.6.", ".1.3.6.1.2.1.31.1.1.1.10."
	if cr.Version == "1" {
		inBase, outBase = ".1.3.6.1.2.1.2.2.1.10.", ".1.3.6.1.2.1.2.2.1.16."
	}
	out := map[int][2]uint64{}
	for start := 0; start < len(ifIndexes); start += 20 {
		end := min(start+20, len(ifIndexes))
		var oids []string
		for _, i := range ifIndexes[start:end] {
			oids = append(oids, inBase+strconv.Itoa(i), outBase+strconv.Itoa(i))
		}
		pkt, err := g.Get(oids)
		if err != nil {
			return out, err
		}
		for _, v := range pkt.Variables {
			if missing(v) {
				continue
			}
			var idx int
			var col int
			switch {
			case strings.HasPrefix(v.Name, inBase):
				idx, _ = strconv.Atoi(strings.TrimPrefix(v.Name, inBase))
			case strings.HasPrefix(v.Name, outBase):
				idx, _ = strconv.Atoi(strings.TrimPrefix(v.Name, outBase))
				col = 1
			default:
				continue
			}
			cur := out[idx]
			cur[col] = gosnmp.ToBigInt(v.Value).Uint64()
			out[idx] = cur
		}
	}
	return out, nil
}
