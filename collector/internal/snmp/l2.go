package snmp

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/gosnmp/gosnmp"
)

// Q-BRIDGE-MIB (RFC 4363), BRIDGE-MIB (RFC 4188), CISCO-VTP-MIB. Semua hanya baca.
const (
	oidDot1qVlanStaticName = ".1.3.6.1.2.1.17.7.1.4.3.1.1" // indeks: VLAN ID
	oidDot1qPvid           = ".1.3.6.1.2.1.17.7.1.4.5.1.1" // indeks: dot1dBasePort
	oidVtpVlanName         = ".1.3.6.1.4.1.9.9.46.1.3.1.1.4"
	oidVtpVlanState        = ".1.3.6.1.4.1.9.9.46.1.3.1.1.2" // operational(1)
	oidStpTimeSinceChange  = ".1.3.6.1.2.1.17.2.3.0"         // TimeTicks
	oidStpTopChanges       = ".1.3.6.1.2.1.17.2.4.0"
	oidStpDesignatedRoot   = ".1.3.6.1.2.1.17.2.5.0" // BridgeId: prioritas 2 oktet + MAC 6 oktet
	oidStpRootPort         = ".1.3.6.1.2.1.17.2.7.0"
	oidStpPortState        = ".1.3.6.1.2.1.17.2.15.1.3" // indeks dot1dStpPort; disabled(1) blocking(2) listening(3) learning(4) forwarding(5) broken(6)
	maxVLANs               = 4096
)

type VLAN struct {
	ID   int    `json:"id"`
	Name string `json:"name"`
}

type STP struct {
	Root         string `json:"root,omitempty"`      // "32768/00:11:22:33:44:55"
	RootPort     int    `json:"root_port,omitempty"` // ifIndex; 0 = switch ini root
	TopChanges   int64  `json:"top_changes"`
	SinceChangeS int64  `json:"since_change_s"`
}

// L2 VLAN, PVID per ifIndex, status STP per ifIndex.
type L2 struct {
	VLANs   []VLAN      `json:"vlans,omitempty"`
	VLANsOK bool        `json:"vlans_ok,omitempty"`
	PVID    map[int]int `json:"pvid,omitempty"`
	STP     *STP        `json:"stp,omitempty"`
	PortSTP map[int]int `json:"port_stp,omitempty"`
}

// BridgeID memformat BridgeId 8 oktet.
func BridgeID(b []byte) string {
	if len(b) != 8 {
		return ""
	}
	return fmt.Sprintf("%d/%02x:%02x:%02x:%02x:%02x:%02x", int(b[0])<<8|int(b[1]), b[2], b[3], b[4], b[5], b[6], b[7])
}

func (c Client) L2(host string, cr Credentials) (L2, error) {
	var out L2
	g, err := c.connect(host, cr)
	if err != nil {
		return out, err
	}
	defer g.Conn.Close()
	walk := walker(g)
	num := func(v gosnmp.SnmpPDU) int64 { return gosnmp.ToBigInt(v.Value).Int64() }

	portIf := map[int]int{}
	_ = walk(oidDot1dBasePortIfIndex, func(idx string, v gosnmp.SnmpPDU) {
		if p, err := strconv.Atoi(idx); err == nil {
			portIf[p] = int(num(v))
		}
	})
	ifOf := func(port int) int {
		if i, ok := portIf[port]; ok && i > 0 {
			return i
		}
		return port
	}

	if err := walk(oidDot1qVlanStaticName, func(idx string, v gosnmp.SnmpPDU) {
		id, err := strconv.Atoi(idx)
		if err != nil || id < 1 || id > 4094 || len(out.VLANs) >= maxVLANs {
			return
		}
		out.VLANs = append(out.VLANs, VLAN{ID: id, Name: text(v.Value)})
	}); err == nil && len(out.VLANs) > 0 {
		out.VLANsOK = true
	} else {
		// Cisco tanpa Q-BRIDGE: VTP, indeks domain.vlan; VLAN 1002-1005 bawaan token ring/FDDI dilewati
		active := map[string]bool{}
		_ = walk(oidVtpVlanState, func(idx string, v gosnmp.SnmpPDU) { active[idx] = num(v) == 1 })
		if err := walk(oidVtpVlanName, func(idx string, v gosnmp.SnmpPDU) {
			parts := strings.Split(idx, ".")
			id, err := strconv.Atoi(parts[len(parts)-1])
			if err != nil || id < 1 || id > 4094 || (id >= 1002 && id <= 1005) || !active[idx] || len(out.VLANs) >= maxVLANs {
				return
			}
			out.VLANs = append(out.VLANs, VLAN{ID: id, Name: text(v.Value)})
		}); err == nil && len(out.VLANs) > 0 {
			out.VLANsOK = true
		}
	}

	_ = walk(oidDot1qPvid, func(idx string, v gosnmp.SnmpPDU) {
		if p, err := strconv.Atoi(idx); err == nil {
			if out.PVID == nil {
				out.PVID = map[int]int{}
			}
			out.PVID[ifOf(p)] = int(num(v))
		}
	})

	if pkt, err := g.Get([]string{oidStpTopChanges, oidStpTimeSinceChange, oidStpDesignatedRoot, oidStpRootPort}); err == nil {
		st := &STP{}
		got := false
		for _, v := range pkt.Variables {
			if missing(v) {
				continue
			}
			switch v.Name {
			case oidStpTopChanges:
				st.TopChanges, got = num(v), true
			case oidStpTimeSinceChange:
				st.SinceChangeS = num(v) / 100
			case oidStpDesignatedRoot:
				if b, ok := v.Value.([]byte); ok {
					st.Root = BridgeID(b)
				}
			case oidStpRootPort:
				if p := int(num(v)); p > 0 {
					st.RootPort = ifOf(p)
				}
			}
		}
		if got {
			out.STP = st
			_ = walk(oidStpPortState, func(idx string, v gosnmp.SnmpPDU) {
				if p, err := strconv.Atoi(idx); err == nil {
					if out.PortSTP == nil {
						out.PortSTP = map[int]int{}
					}
					out.PortSTP[ifOf(p)] = int(num(v))
				}
			})
		}
	}
	return out, nil
}
