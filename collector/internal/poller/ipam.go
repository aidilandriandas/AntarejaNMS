package poller

import (
	"context"
	"strings"

	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// Router/L3 yang tabel ARP-nya dibaca untuk IPAM.
var arpCategories = map[string]bool{"core": true, "distribution": true, "firewall": true}

func (p *Poller) collectIPAM(ctx context.Context, d store.Device, cr snmp.Credentials, objectID string) {
	if addrs, err := p.SNMP.Addresses(d.Host, cr); err == nil {
		rows := make([]store.IPRow, 0, len(addrs))
		for _, a := range addrs {
			rows = append(rows, store.IPRow{IP: a.IP, IfIndex: a.IfIndex, Prefix: a.Prefix})
		}
		if err := p.Store.SaveIPs(ctx, d.ID, rows); err != nil {
			p.Log.Warn("simpan IP perangkat gagal", "perangkat", d.ID, "galat", err)
		}
	}
	if !arpCategories[d.Category] && !strings.HasPrefix(objectID, ".1.3.6.1.4.1.14988") {
		return
	}
	arp, err := p.SNMP.ARP(d.Host, cr)
	if err != nil || len(arp) == 0 {
		return
	}
	rows := make([]store.ARPRow, 0, len(arp))
	for _, a := range arp {
		rows = append(rows, store.ARPRow{IfIndex: a.IfIndex, IP: a.IP, MAC: a.MAC})
	}
	n, err := p.Store.SaveARP(ctx, d.ID, rows)
	if err != nil {
		p.Log.Warn("simpan ARP gagal", "perangkat", d.ID, "galat", err)
		return
	}
	if n > 0 {
		p.Log.Info("pergantian MAC di tabel ARP", "router", d.ID, "jumlah", n)
	}
}
