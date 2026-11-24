package snmp

import (
	"strings"

	"github.com/gosnmp/gosnmp"
)

// BGP4-MIB (RFC 4273) bgpPeerTable .1.3.6.1.2.1.15.3.1, indeks bgpPeerRemoteAddr (IPv4).
// OSPF-MIB (RFC 4750) ospfNbrTable .1.3.6.1.2.1.14.10.1, indeks ospfNbrIpAddr + ospfNbrAddressLessIndex.
const (
	oidBgpLocalAs        = ".1.3.6.1.2.1.15.2.0"
	oidBgpPeerState      = ".1.3.6.1.2.1.15.3.1.2" // idle(1) connect(2) active(3) opensent(4) openconfirm(5) established(6)
	oidBgpPeerAdmin      = ".1.3.6.1.2.1.15.3.1.3" // stop(1) start(2)
	oidBgpPeerRemoteAs   = ".1.3.6.1.2.1.15.3.1.9"
	oidBgpPeerEstablTime = ".1.3.6.1.2.1.15.3.1.16" // detik sejak terakhir established / turun
	oidOspfNbrRtrID      = ".1.3.6.1.2.1.14.10.1.3"
	oidOspfNbrState      = ".1.3.6.1.2.1.14.10.1.6" // down(1) attempt(2) init(3) twoWay(4) exchangeStart(5) exchange(6) loading(7) full(8)

	BGPEstablished = 6
	BGPAdminStart  = 2
	OSPFFull       = 8
	OSPFTwoWay     = 4
	maxPeers       = 2000
)

type BGPPeer struct {
	Addr      string `json:"addr"`
	RemoteAS  int64  `json:"remote_as"`
	State     int    `json:"state"`
	Admin     int    `json:"admin"`
	SinceSecs int64  `json:"since_s"`
}

type OSPFNeighbor struct {
	Addr     string `json:"addr"`
	RouterID string `json:"router_id"`
	State    int    `json:"state"`
}

// Routing tabel BGP dan OSPF. OK = walk berhasil (walau kosong); false = galat SNMP saat membaca.
type Routing struct {
	LocalAS int64          `json:"local_as,omitempty"`
	BGP     []BGPPeer      `json:"bgp,omitempty"`
	BGPOK   bool           `json:"bgp_ok,omitempty"`
	OSPF    []OSPFNeighbor `json:"ospf,omitempty"`
	OSPFOK  bool           `json:"ospf_ok,omitempty"`
}

// Routing membaca sesi BGP dan tetangga OSPF (hanya baca).
func (c Client) Routing(host string, cr Credentials) (Routing, error) {
	var out Routing
	g, err := c.connect(host, cr)
	if err != nil {
		return out, err
	}
	defer g.Conn.Close()
	walk := walker(g)
	num := func(v gosnmp.SnmpPDU) int64 { return gosnmp.ToBigInt(v.Value).Int64() }

	peers := map[string]*BGPPeer{}
	var order []string
	if err := walk(oidBgpPeerState, func(idx string, v gosnmp.SnmpPDU) {
		ip := ipFromIdx(strings.Split(idx, "."))
		if ip == "" || len(peers) >= maxPeers {
			return
		}
		peers[idx] = &BGPPeer{Addr: ip, State: int(num(v))}
		order = append(order, idx)
	}); err == nil {
		// berhasil walau kosong: semua sesi yang dulu ada dianggap hilang (dicatat penyimpan)
		out.BGPOK = true
		for col, set := range map[string]func(*BGPPeer, gosnmp.SnmpPDU){
			oidBgpPeerAdmin:      func(p *BGPPeer, v gosnmp.SnmpPDU) { p.Admin = int(num(v)) },
			oidBgpPeerRemoteAs:   func(p *BGPPeer, v gosnmp.SnmpPDU) { p.RemoteAS = num(v) },
			oidBgpPeerEstablTime: func(p *BGPPeer, v gosnmp.SnmpPDU) { p.SinceSecs = num(v) },
		} {
			_ = walk(col, func(idx string, v gosnmp.SnmpPDU) {
				if p, ok := peers[idx]; ok {
					set(p, v)
				}
			})
		}
		for _, k := range order {
			out.BGP = append(out.BGP, *peers[k])
		}
		if pkt, err := g.Get([]string{oidBgpLocalAs}); len(peers) > 0 && err == nil {
			for _, v := range pkt.Variables {
				if !missing(v) {
					out.LocalAS = num(v)
				}
			}
		}
	}

	nbrs := map[string]*OSPFNeighbor{}
	var norder []string
	if err := walk(oidOspfNbrState, func(idx string, v gosnmp.SnmpPDU) {
		parts := strings.Split(idx, ".")
		if len(parts) < 4 || len(nbrs) >= maxPeers {
			return
		}
		ip := ipFromIdx(parts[:4])
		if ip == "" {
			return
		}
		nbrs[idx] = &OSPFNeighbor{Addr: ip, State: int(num(v))}
		norder = append(norder, idx)
	}); err == nil {
		out.OSPFOK = true
		_ = walk(oidOspfNbrRtrID, func(idx string, v gosnmp.SnmpPDU) {
			if n, ok := nbrs[idx]; ok {
				n.RouterID = text(v.Value)
			}
		})
		for _, k := range norder {
			out.OSPF = append(out.OSPF, *nbrs[k])
		}
	}
	return out, nil
}
