package snmp

import (
	"strconv"
	"strings"
	"testing"

	"github.com/gosnmp/gosnmp"
)

// fakeWalk meniru BulkWalk: oid lengkap -> nilai.
func fakeWalk(rows map[string]any) func(string, func(string, gosnmp.SnmpPDU)) error {
	return func(base string, fn func(string, gosnmp.SnmpPDU)) error {
		for oid, v := range rows {
			if strings.HasPrefix(oid, base+".") {
				fn(strings.TrimPrefix(oid, base+"."), gosnmp.SnmpPDU{Name: oid, Value: v})
			}
		}
		return nil
	}
}

func TestParseWireless(t *testing.T) {
	sta := oidUbntSta
	m := ".1.24.90.214.17.34.51" // wlIndex 1, MAC 18:5a:d6:11:22:33
	af := oidAirFiberStat
	ltu := oidLTUSta + ".%s.4.24.214.1.2.3"
	rows := map[string]any{
		sta + ".2" + m: []byte("CPE PIT-A"), sta + ".3" + m: -62, sta + ".4" + m: -95, sta + ".5" + m: 2300, sta + ".6" + m: 94,
		sta + ".8" + m: 88, sta + ".9" + m: 71, sta + ".10" + m: "10.17.36.120", sta + ".11" + m: 130000, sta + ".12" + m: 144000,
		sta + ".15" + m: uint32(360000),
		af + ".4.1":     5200, af + ".5.1": 701000000, af + ".6.1": 698000000, af + ".11.1": -55, af + ".12.1": 1, af + ".14.1": -57,
		af + ".15.1": 1, af + ".19.1": -56, af + ".20.1": 1, af + ".22.1": -60, af + ".23.1": 2, af + ".26.1": 1, af + ".44.1": 86400,
		af + ".45.1": []byte("04:18:D6:AA:BB:CC"), af + ".46.1": []byte("10.17.30.2"),
		strings.Replace(ltu, "%s", "5", 1): -66, strings.Replace(ltu, "%s", "6", 1): -64, strings.Replace(ltu, "%s", "13", 1): []byte("LTU-HAUL"),
		strings.Replace(ltu, "%s", "23", 1): 3, strings.Replace(ltu, "%s", "25", 1): "10.17.40.9",
	}
	got, err := parseWireless(fakeWalk(rows))
	if err != nil || len(got) != 3 {
		t.Fatal("harus 3 link", len(got), err)
	}
	a := got[0]
	if a.Kind != "airmax" || a.MAC != "18:5a:d6:11:22:33" || a.Name != "CPE PIT-A" || a.IP != "10.17.36.120" || *a.Signal != -62 || *a.CCQ != 94 ||
		*a.Quality != 88 || *a.Cap != 71 || *a.DistanceM != 2300 || *a.TxMbps != 130 || *a.UptimeS != 3600 || !a.Connected {
		t.Fatalf("airMAX salah: %+v", a)
	}
	f := got[1]
	if f.Kind != "airfiber" || f.MAC != "04:18:d6:aa:bb:cc" || f.IP != "10.17.30.2" || *f.Signal != -55 || *f.RemoteSignal != -56 ||
		*f.RxMbps != 701 || *f.DistanceM != 5200 || *f.UptimeS != 86400 || !f.Connected {
		t.Fatalf("airFiber salah: %+v (rantai lawan 1 tidak sah harus dilewati)", f)
	}
	l := got[2]
	if l.Kind != "ltu" || l.MAC != "04:18:d6:01:02:03" || l.Name != "LTU-HAUL" || *l.Signal != -64 || *l.DistanceM != 3000 || l.IP != "10.17.40.9" {
		t.Fatalf("LTU salah: %+v", l)
	}
	// link airFiber putus
	rows[af+".26.1"] = 0
	got, _ = parseWireless(fakeWalk(rows))
	if got[1].Connected {
		t.Fatal("radioLinkState 0 = putus")
	}
	if normMAC("0418d6aabbcc") != "04:18:d6:aa:bb:cc" || normMAC("00:00:00:00:00:00") != "" || normMAC("x") != "" {
		t.Fatal("normMAC")
	}
}

// AP PTMP dengan dua radio (indeks wlan 1 dan 2) dan banyak station: semua terbaca, satu baris per station.
func TestParseWirelessPTMP(t *testing.T) {
	rows := map[string]any{}
	for i, wl := range []string{"1", "1", "1", "2", "2"} {
		idx := "." + wl + ".24.90.214.0.80." + strconv.Itoa(10+i)
		rows[oidUbntSta+".2"+idx] = []byte("STATION " + strconv.Itoa(i+1))
		rows[oidUbntSta+".3"+idx] = -60 - i
		rows[oidUbntSta+".10"+idx] = "10.17.50." + strconv.Itoa(10+i)
	}
	got, err := parseWireless(fakeWalk(rows))
	if err != nil || len(got) != 5 {
		t.Fatal("5 station dari dua radio", len(got), err)
	}
	seen := map[string]bool{}
	for _, l := range got {
		if seen[l.MAC] || l.Signal == nil || l.IP == "" {
			t.Fatalf("station ganda atau tidak lengkap: %+v", l)
		}
		seen[l.MAC] = true
	}
}

func TestParseWirelessMikrotik(t *testing.T) {
	idx := ".76.94.12.1.2.3.5" // 4c:5e:0c:01:02:03, wlan ifIndex 5
	rows := map[string]any{
		oidMtxrRtab + ".3" + idx: -67, oidMtxrRtab + ".8" + idx: uint(130000000), oidMtxrRtab + ".9" + idx: uint(117000000),
		oidMtxrRtab + ".11" + idx: uint32(720000), oidMtxrRtab + ".12" + idx: 38, oidMtxrRtab + ".20" + idx: []byte("STATION-MT18"),
	}
	got, err := parseWireless(fakeWalk(rows))
	if err != nil || len(got) != 1 {
		t.Fatal(len(got), err)
	}
	l := got[0]
	if l.Kind != "mikrotik" || l.MAC != "4c:5e:0c:01:02:03" || l.Name != "STATION-MT18" || *l.Signal != -67 || *l.Noise != -105 ||
		*l.TxMbps != 130 || *l.RxMbps != 117 || *l.UptimeS != 7200 || l.IP != "" {
		t.Fatalf("MikroTik salah: %+v", l)
	}
}
