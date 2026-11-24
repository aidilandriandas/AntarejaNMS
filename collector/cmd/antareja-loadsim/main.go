// antareja-loadsim: simulator agen SNMP v2c untuk uji beban ANTAREJA. BUKAN untuk produksi.
//
// Menjalankan N agen tiruan, masing-masing di port UDP sendiri (basis+i), menjawab GET/GETNEXT/GETBULK untuk grup
// system, ifTable/ifXTable (counter terus naik), ipAddrTable, dan tabel MAC Q-BRIDGE. Jalankan di network namespace
// yang sama dengan collector, lalu daftarkan perangkat dengan host 127.x.y.z (ping loopback) dan port masing-masing:
//
//	antareja-loadsim -n 1000 -port 20000 -ifaces 24 -community uji
//
// Agen ke-i mendengar di 127.(1+i/250).(i%250).(1+i%200) port basis+i (lihat -loopback).
package main

import (
	"flag"
	"fmt"
	"log"
	"net"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/gosnmp/gosnmp"
)

type entry struct {
	oid   string
	parts []int
	val   func() gosnmp.SnmpPDU
}

type agent struct {
	idx     int
	entries []entry // urut OID
}

var start = time.Now()

func oidParts(o string) []int {
	ss := strings.Split(strings.TrimPrefix(o, "."), ".")
	out := make([]int, len(ss))
	for i, s := range ss {
		out[i], _ = strconv.Atoi(s)
	}
	return out
}

func less(a, b []int) bool {
	for i := 0; i < len(a) && i < len(b); i++ {
		if a[i] != b[i] {
			return a[i] < b[i]
		}
	}
	return len(a) < len(b)
}

func newAgent(i, ifaces, macs int) *agent {
	a := &agent{idx: i}
	add := func(oid string, f func() gosnmp.SnmpPDU) {
		a.entries = append(a.entries, entry{oid: oid, parts: oidParts(oid), val: f})
	}
	str := func(oid, v string) {
		add(oid, func() gosnmp.SnmpPDU { return gosnmp.SnmpPDU{Name: oid, Type: gosnmp.OctetString, Value: []byte(v)} })
	}
	integer := func(oid string, v int) {
		add(oid, func() gosnmp.SnmpPDU { return gosnmp.SnmpPDU{Name: oid, Type: gosnmp.Integer, Value: v} })
	}
	str(".1.3.6.1.2.1.1.1.0", fmt.Sprintf("Simulator switch %d", i))
	add(".1.3.6.1.2.1.1.2.0", func() gosnmp.SnmpPDU {
		return gosnmp.SnmpPDU{Name: ".1.3.6.1.2.1.1.2.0", Type: gosnmp.ObjectIdentifier, Value: ".1.3.6.1.4.1.8072.3.2.10"}
	})
	add(".1.3.6.1.2.1.1.3.0", func() gosnmp.SnmpPDU {
		return gosnmp.SnmpPDU{Name: ".1.3.6.1.2.1.1.3.0", Type: gosnmp.TimeTicks, Value: uint32(time.Since(start).Seconds()*100) + 360000}
	})
	str(".1.3.6.1.2.1.1.5.0", fmt.Sprintf("sim-%04d", i))
	str(".1.3.6.1.2.1.1.6.0", "uji beban")
	for p := 1; p <= ifaces; p++ {
		p := p
		rate := uint64(1000+i*7+p*13) * 1000 // byte/detik, berbeda per port
		counter := func(oid string, mult uint64) {
			add(oid, func() gosnmp.SnmpPDU {
				return gosnmp.SnmpPDU{Name: oid, Type: gosnmp.Counter64, Value: uint64(time.Since(start).Seconds()) * rate * mult / 10}
			})
		}
		integer(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.1.%d", p), p)
		str(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.2.%d", p), fmt.Sprintf("port%d", p))
		integer(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.3.%d", p), 6)
		add(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.5.%d", p), func() gosnmp.SnmpPDU {
			return gosnmp.SnmpPDU{Name: fmt.Sprintf(".1.3.6.1.2.1.2.2.1.5.%d", p), Type: gosnmp.Gauge32, Value: uint(1000000000)}
		})
		mac := []byte{0x02, 0x00, byte(i >> 8), byte(i), 0, byte(p)}
		add(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.6.%d", p), func() gosnmp.SnmpPDU {
			return gosnmp.SnmpPDU{Name: fmt.Sprintf(".1.3.6.1.2.1.2.2.1.6.%d", p), Type: gosnmp.OctetString, Value: mac}
		})
		integer(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.7.%d", p), 1)
		oper := 1
		if p%5 == 0 {
			oper = 2
		}
		integer(fmt.Sprintf(".1.3.6.1.2.1.2.2.1.8.%d", p), oper)
		str(fmt.Sprintf(".1.3.6.1.2.1.31.1.1.1.1.%d", p), fmt.Sprintf("ether%d", p))
		counter(fmt.Sprintf(".1.3.6.1.2.1.31.1.1.1.6.%d", p), 10)
		counter(fmt.Sprintf(".1.3.6.1.2.1.31.1.1.1.10.%d", p), 7)
		add(fmt.Sprintf(".1.3.6.1.2.1.31.1.1.1.15.%d", p), func() gosnmp.SnmpPDU {
			return gosnmp.SnmpPDU{Name: fmt.Sprintf(".1.3.6.1.2.1.31.1.1.1.15.%d", p), Type: gosnmp.Gauge32, Value: uint(1000)}
		})
		str(fmt.Sprintf(".1.3.6.1.2.1.31.1.1.1.18.%d", p), "")
	}
	// alamat IP interface
	ip := fmt.Sprintf("10.%d.%d.1", 100+i/250, i%250)
	integer(".1.3.6.1.2.1.4.20.1.2."+ip, 1)
	str(".1.3.6.1.2.1.4.20.1.3."+ip, "")
	add(".1.3.6.1.2.1.4.20.1.3."+ip, func() gosnmp.SnmpPDU {
		return gosnmp.SnmpPDU{Name: ".1.3.6.1.2.1.4.20.1.3." + ip, Type: gosnmp.IPAddress, Value: "255.255.255.0"}
	})
	// tabel MAC Q-BRIDGE: VLAN 10, MAC klien di port 1..macs
	for m := 1; m <= macs; m++ {
		idx := fmt.Sprintf("10.2.%d.%d.%d.0.%d", (i>>8)&255, i&255, m, m)
		integer(".1.3.6.1.2.1.17.7.1.2.2.1.2."+idx, (m-1)%ifaces+1)
		integer(".1.3.6.1.2.1.17.7.1.2.2.1.3."+idx, 3)
	}
	sort.SliceStable(a.entries, func(x, y int) bool { return less(a.entries[x].parts, a.entries[y].parts) })
	// buang duplikat (OID sama: yang terakhir ditambahkan dipakai)
	out := a.entries[:0]
	for k, e := range a.entries {
		if k+1 < len(a.entries) && a.entries[k+1].oid == e.oid {
			continue
		}
		out = append(out, e)
	}
	a.entries = out
	return a
}

// next: indeks entri pertama yang OID-nya > oid.
func (a *agent) next(oid string) int {
	p := oidParts(oid)
	return sort.Search(len(a.entries), func(k int) bool { return less(p, a.entries[k].parts) })
}

func (a *agent) exact(oid string) (gosnmp.SnmpPDU, bool) {
	k := sort.Search(len(a.entries), func(k int) bool { return !less(a.entries[k].parts, oidParts(oid)) })
	if k < len(a.entries) && a.entries[k].oid == oid {
		return a.entries[k].val(), true
	}
	return gosnmp.SnmpPDU{Name: oid, Type: gosnmp.NoSuchObject}, false
}

func (a *agent) handle(req *gosnmp.SnmpPacket) *gosnmp.SnmpPacket {
	resp := &gosnmp.SnmpPacket{Version: req.Version, Community: req.Community, PDUType: gosnmp.GetResponse, RequestID: req.RequestID}
	nextOf := func(oid string) gosnmp.SnmpPDU {
		k := a.next(oid)
		if k >= len(a.entries) {
			return gosnmp.SnmpPDU{Name: oid, Type: gosnmp.EndOfMibView}
		}
		return a.entries[k].val()
	}
	switch req.PDUType {
	case gosnmp.GetRequest:
		for _, v := range req.Variables {
			pdu, _ := a.exact(v.Name)
			resp.Variables = append(resp.Variables, pdu)
		}
	case gosnmp.GetNextRequest:
		for _, v := range req.Variables {
			resp.Variables = append(resp.Variables, nextOf(v.Name))
		}
	case gosnmp.GetBulkRequest:
		for _, v := range req.Variables {
			oid := v.Name
			for r := 0; r < int(req.MaxRepetitions) && r < 50; r++ {
				pdu := nextOf(oid)
				resp.Variables = append(resp.Variables, pdu)
				if pdu.Type == gosnmp.EndOfMibView {
					break
				}
				oid = pdu.Name
			}
		}
	}
	return resp
}

func main() {
	n := flag.Int("n", 500, "jumlah agen")
	base := flag.Int("port", 20000, "port UDP pertama")
	ifaces := flag.Int("ifaces", 24, "port per agen")
	macs := flag.Int("macs", 10, "MAC di tabel FDB per agen")
	community := flag.String("community", "uji", "community v2c")
	loop := flag.Bool("loopback", true, "agen ke-i mendengar di 127.(1+i/250).(i%250).(1+i%200) supaya alamat jawaban sama dengan alamat yang ditanya")
	flag.Parse()
	dec := &gosnmp.GoSNMP{}
	var served atomic.Int64
	var wg sync.WaitGroup
	for i := 0; i < *n; i++ {
		a := newAgent(i, *ifaces, *macs)
		addr := fmt.Sprintf(":%d", *base+i)
		if *loop {
			addr = fmt.Sprintf("127.%d.%d.%d:%d", 1+i/250, i%250, 1+i%200, *base+i)
		}
		pc, err := net.ListenPacket("udp", addr)
		if err != nil {
			log.Fatalf("port %d: %v", *base+i, err)
		}
		wg.Add(1)
		go func() {
			defer wg.Done()
			buf := make([]byte, 65535)
			for {
				k, addr, err := pc.ReadFrom(buf)
				if err != nil {
					return
				}
				req, err := dec.SnmpDecodePacket(append([]byte(nil), buf[:k]...))
				if err != nil || req.Community != *community {
					continue
				}
				out, err := a.handle(req).MarshalMsg()
				if err == nil {
					_, _ = pc.WriteTo(out, addr)
					served.Add(1)
				}
			}
		}()
	}
	log.Printf("%d agen SNMP di port %d-%d, %d port per agen", *n, *base, *base+*n-1, *ifaces)
	go func() {
		for range time.Tick(30 * time.Second) {
			log.Printf("permintaan dijawab: %d", served.Load())
		}
	}()
	wg.Wait()
}
