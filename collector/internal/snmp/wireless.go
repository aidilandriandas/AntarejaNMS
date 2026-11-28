package snmp

import (
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/gosnmp/gosnmp"
)

// Link wireless Ubiquiti: siapa tersambung ke radio ini dan seberapa bagus sinyalnya.
//
// Sumber OID (teks MIB Ubiquiti, salinan di repositori LibreNMS mibs/ubnt):
//   - UBNT-AirMAX-MIB ubntStaTable .1.3.6.1.4.1.41112.1.4.7.1, INDEX { ubntWlStatIndex, ubntStaMac }:
//     2 Name, 3 Signal (dBm), 4 NoiseFloor, 5 Distance, 6 Ccq (%), 8 Amq, 9 Amc, 10 LastIp, 11 TxRate, 12 RxRate,
//     15 ConnTime (TimeTicks).
//   - UBNT-AirFIBER-MIB airFiberStatusTable .1.3.6.1.4.1.41112.1.3.2.1, INDEX { airFiberStatusIndex }:
//     4 radioLinkDistM, 5 rxCapacity (bit/s), 6 txCapacity (bit/s), 11 rxPower0 (dBm), 12 rxPower0Valid,
//     14 rxPower1 (dBm), 15 rxPower1Valid, 19 remoteRXPower0 (dBm), 20 remoteRXPower0Valid, 22 remoteRXPower1,
//     23 remoteRXPower1Valid, 26 radioLinkState (0 down, 1 up), 44 linkUpTime (detik), 45 remoteMAC, 46 remoteIP.
//   - UBNT-AFLTU-MIB afLTUStationTable .1.3.6.1.4.1.41112.1.10.1.4.1, INDEX { afLTUStaRemoteMac }:
//     1 TxRate, 2 RxRate (kelipatan modulasi "x", tidak dipakai), 3 TxCapacity, 4 RxCapacity (Kbps),
//     5 RxPower0, 6 RxPower1 (dBm), 13 RemoteDevName, 16 RemoteRxPower0, 17 RemoteRxPower1 (dBm),
//     23 RemoteDistance (km), 25 RemoteLastIp.
//   - MIKROTIK-MIB mtxrWlRtabTable .1.3.6.1.4.1.14988.1.1.1.2.1, INDEX { mtxrWlRtabAddr (MAC lawan), mtxrWlRtabIface }:
//     3 Strength (dBm), 8 TxRate, 9 RxRate (bit/s), 11 Uptime (TimeTicks), 12 SignalToNoise (dB, 0 = tidak ada),
//     20 RadioName. Tidak ada kolom IP lawan; IP dicari dari tabel ARP saat disimpan.
//
// VERIFIKASI di perangkat asli: satuan ubntStaTxRate/RxRate (dianggap Kbps seperti ubntWlStatTxRate),
// ubntStaDistance (dianggap meter), dan ubntStaConnTime (SYNTAX TimeTicks, dibaca seperdua ratus detik).
const (
	oidUbntSta      = ".1.3.6.1.4.1.41112.1.4.7.1"
	oidAirFiberStat = ".1.3.6.1.4.1.41112.1.3.2.1"
	oidLTUSta       = ".1.3.6.1.4.1.41112.1.10.1.4.1"
	oidMtxrRtab     = ".1.3.6.1.4.1.14988.1.1.1.2.1"
)

// WirelessLink satu lawan bicara radio: station di AP airMAX/LTU, atau ujung lain link airFiber.
type WirelessLink struct {
	Kind           string // airmax | airfiber | ltu | mikrotik
	MAC            string // aa:bb:cc:dd:ee:ff
	Name, IP       string
	Signal         *float64 // dBm, diterima radio ini dari lawan
	RemoteSignal   *float64 // dBm, diterima lawan (airFiber, LTU)
	Noise, CCQ     *float64
	Quality, Cap   *float64 // airMAX quality & capacity (%)
	DistanceM      *int
	TxMbps, RxMbps *float64
	UptimeS        *int64
	Connected      bool
}

var hexMAC = regexp.MustCompile(`[0-9a-fA-F]{2}`)

// normMAC menyeragamkan MAC teks ("04:18:D6:..", "0418d6..", "04-18-..") jadi aa:bb:cc:dd:ee:ff.
func normMAC(s string) string {
	s = strings.TrimSpace(s)
	h := strings.Map(func(r rune) rune {
		if strings.ContainsRune("0123456789abcdefABCDEF", r) {
			return r
		}
		return -1
	}, s)
	if len(h) != 12 {
		return ""
	}
	p := hexMAC.FindAllString(strings.ToLower(h), -1)
	m := strings.Join(p, ":")
	if m == "00:00:00:00:00:00" {
		return ""
	}
	return m
}

func fptr(f float64) *float64 { return &f }

// best: daya terima terbaik dari dua rantai yang sah (nil bila tidak ada).
func best(vals ...*float64) *float64 {
	var out *float64
	for _, v := range vals {
		if v != nil && *v < 0 && *v > -120 && (out == nil || *v > *out) {
			out = v
		}
	}
	return out
}

// Wireless membaca tabel station airMAX, status airFiber, dan station LTU. Tabel yang tidak ada di perangkat
// dilewati; galat hanya bila tidak satu pun tabel bisa dibaca (supaya daftar lama tidak terhapus karena timeout).
func (c Client) Wireless(host string, cr Credentials, sysObjectID string) ([]WirelessLink, error) {
	g, err := c.connect(host, cr)
	if err != nil {
		return nil, err
	}
	defer g.Conn.Close()
	w := walker(g)
	// Hanya tabel milik vendor perangkat (sysObjectID); tidak dikenal = coba semua
	ubnt := strings.HasPrefix(sysObjectID, ".1.3.6.1.4.1.41112") || strings.HasPrefix(sysObjectID, ".1.3.6.1.4.1.10002")
	mtik := strings.HasPrefix(sysObjectID, ".1.3.6.1.4.1.14988")
	return parseWireless(func(base string, fn func(idx string, v gosnmp.SnmpPDU)) error {
		isMT := strings.HasPrefix(base, ".1.3.6.1.4.1.14988")
		if (ubnt && isMT) || (mtik && !isMT) {
			return nil
		}
		return w(base, fn)
	})
}

// parseWireless mengurai tabel lewat fungsi walk (dipisah supaya bisa diuji tanpa perangkat).
func parseWireless(walk func(base string, fn func(idx string, v gosnmp.SnmpPDU)) error) ([]WirelessLink, error) {
	num := func(v gosnmp.SnmpPDU) float64 { return float64(gosnmp.ToBigInt(v.Value).Int64()) }
	var out []WirelessLink
	okAny := false

	// airMAX: indeks = wlIndex.m1.m2.m3.m4.m5.m6
	sta := map[string]*WirelessLink{}
	err1 := walk(oidUbntSta, func(idx string, v gosnmp.SnmpPDU) {
		parts := strings.Split(idx, ".")
		if len(parts) != 8 {
			return
		}
		col := parts[0]
		mac, ok := macFromIdx(parts[2:])
		if !ok {
			return
		}
		key := parts[1] + "/" + mac
		l := sta[key]
		if l == nil {
			l = &WirelessLink{Kind: "airmax", MAC: mac, Connected: true}
			sta[key] = l
		}
		switch col {
		case "2":
			l.Name = text(v.Value)
		case "3":
			if f := num(v); f != 0 {
				l.Signal = fptr(f)
			}
		case "4":
			if f := num(v); f != 0 {
				l.Noise = fptr(f)
			}
		case "5":
			if d := int(num(v)); d > 0 {
				l.DistanceM = &d
			}
		case "6":
			l.CCQ = fptr(num(v))
		case "8":
			l.Quality = fptr(num(v))
		case "9":
			l.Cap = fptr(num(v))
		case "10":
			if s := text(v.Value); s != "0.0.0.0" {
				l.IP = s
			}
		case "11":
			if f := num(v); f > 0 {
				l.TxMbps = fptr(f / 1000)
			}
		case "12":
			if f := num(v); f > 0 {
				l.RxMbps = fptr(f / 1000)
			}
		case "15":
			t := int64(num(v) / 100)
			l.UptimeS = &t
		}
	})
	if err1 == nil {
		okAny = true
	}
	keys := make([]string, 0, len(sta))
	for k := range sta {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		out = append(out, *sta[k])
	}

	// airFiber: satu baris status per radio; lawan dikenali dari remoteMAC/remoteIP
	af := map[string]map[int]gosnmp.SnmpPDU{}
	err2 := walk(oidAirFiberStat, func(idx string, v gosnmp.SnmpPDU) {
		parts := strings.SplitN(idx, ".", 2)
		if len(parts) != 2 {
			return
		}
		col, _ := strconv.Atoi(parts[0])
		switch col {
		case 4, 5, 6, 11, 12, 14, 15, 19, 20, 22, 23, 26, 44, 45, 46:
			if af[parts[1]] == nil {
				af[parts[1]] = map[int]gosnmp.SnmpPDU{}
			}
			af[parts[1]][col] = v
		}
	})
	if err2 == nil {
		okAny = true
	}
	afIdx := make([]string, 0, len(af))
	for k := range af {
		afIdx = append(afIdx, k)
	}
	sort.Strings(afIdx)
	for _, i := range afIdx {
		r := af[i]
		mac := normMAC(text(r[45].Value))
		ip := strings.TrimSpace(text(r[46].Value))
		if mac == "" && ip == "" {
			continue
		}
		valid := func(c int) bool { p, ok := r[c]; return !ok || num(p) == 1 } // TruthValue true(1); kolom tidak ada = anggap sah
		pw := func(c, validCol int) *float64 {
			p, ok := r[c]
			if !ok || !valid(validCol) {
				return nil
			}
			return fptr(num(p))
		}
		l := WirelessLink{Kind: "airfiber", MAC: mac, IP: ip, Connected: num(r[26]) == 1,
			Signal: best(pw(11, 12), pw(14, 15)), RemoteSignal: best(pw(19, 20), pw(22, 23))}
		if mac == "" {
			l.MAC = "ip:" + ip
		}
		if d := int(num(r[4])); d > 0 {
			l.DistanceM = &d
		}
		if f := num(r[5]); f > 0 {
			l.RxMbps = fptr(f / 1e6)
		}
		if f := num(r[6]); f > 0 {
			l.TxMbps = fptr(f / 1e6)
		}
		if _, ok := r[44]; ok {
			t := int64(num(r[44]))
			l.UptimeS = &t
		}
		out = append(out, l)
	}

	// LTU: indeks = MAC lawan (6 sub-id)
	ltu := map[string]*WirelessLink{}
	err3 := walk(oidLTUSta, func(idx string, v gosnmp.SnmpPDU) {
		parts := strings.Split(idx, ".")
		if len(parts) != 7 {
			return
		}
		mac, ok := macFromIdx(parts[1:])
		if !ok {
			return
		}
		l := ltu[mac]
		if l == nil {
			l = &WirelessLink{Kind: "ltu", MAC: mac, Connected: true}
			ltu[mac] = l
		}
		switch parts[0] {
		case "3":
			if f := num(v); f > 0 {
				l.TxMbps = fptr(f / 1000)
			}
		case "4":
			if f := num(v); f > 0 {
				l.RxMbps = fptr(f / 1000)
			}
		case "5", "6":
			l.Signal = best(l.Signal, fptr(num(v)))
		case "13":
			l.Name = text(v.Value)
		case "16", "17":
			l.RemoteSignal = best(l.RemoteSignal, fptr(num(v)))
		case "23":
			if d := int(num(v) * 1000); d > 0 {
				l.DistanceM = &d
			}
		case "25":
			if s := text(v.Value); s != "0.0.0.0" {
				l.IP = s
			}
		}
	})
	if err3 == nil {
		okAny = true
	}
	macs := make([]string, 0, len(ltu))
	for m := range ltu {
		macs = append(macs, m)
	}
	sort.Strings(macs)
	for _, m := range macs {
		out = append(out, *ltu[m])
	}

	// MikroTik: indeks = m1..m6.ifIndex (MAC lawan lalu interface wireless lokal)
	mt := map[string]*WirelessLink{}
	var snr = map[string]float64{}
	err4 := walk(oidMtxrRtab, func(idx string, v gosnmp.SnmpPDU) {
		parts := strings.Split(idx, ".")
		if len(parts) != 8 {
			return
		}
		mac, ok := macFromIdx(parts[1:7])
		if !ok {
			return
		}
		key := mac + "/" + parts[7]
		l := mt[key]
		if l == nil {
			l = &WirelessLink{Kind: "mikrotik", MAC: mac, Connected: true}
			mt[key] = l
		}
		switch parts[0] {
		case "3":
			if f := num(v); f != 0 {
				l.Signal = fptr(f)
			}
		case "8":
			if f := num(v); f > 0 {
				l.TxMbps = fptr(f / 1e6)
			}
		case "9":
			if f := num(v); f > 0 {
				l.RxMbps = fptr(f / 1e6)
			}
		case "11":
			t := int64(num(v) / 100)
			l.UptimeS = &t
		case "12":
			if f := num(v); f != 0 {
				snr[key] = f
			}
		case "20":
			l.Name = text(v.Value)
		}
	})
	if err4 == nil {
		okAny = true
	}
	mkeys := make([]string, 0, len(mt))
	for k := range mt {
		mkeys = append(mkeys, k)
	}
	sort.Strings(mkeys)
	seenMT := map[string]bool{}
	for _, k := range mkeys {
		l := mt[k]
		if s, ok := snr[k]; ok && l.Signal != nil {
			l.Noise = fptr(*l.Signal - s) // noise floor = sinyal - SNR
		}
		if seenMT[l.MAC] { // MAC sama di dua interface: simpan sekali
			continue
		}
		seenMT[l.MAC] = true
		out = append(out, *l)
	}

	if !okAny {
		return nil, errors.Join(fmt.Errorf("tabel wireless tidak terbaca"), err1, err2, err3, err4)
	}
	return out, nil
}
