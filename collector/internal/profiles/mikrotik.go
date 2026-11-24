package profiles

import (
	"fmt"
	"math"
)

// Sumber: MIKROTIK-MIB (MikroTik RouterOS, enterprise 14988) — tabel health (mtxrHealth) dan wireless
// (mtxrWlStatTable = mode station, mtxrWlApTable = mode AP, mtxrWlRtabTable = registration table).
// CPU & memori RouterOS dibaca dari HOST-RESOURCES (profil generik).
// VERIFIKASI: skala suhu/tegangan (desi) dan satuan rate pada versi RouterOS yang dipakai di lapangan.

const (
	mtxrHlVoltage    = ".1.3.6.1.4.1.14988.1.1.3.8.0"  // desivolt (RouterOS 6)
	mtxrHlTemp       = ".1.3.6.1.4.1.14988.1.1.3.10.0" // desi-°C
	mtxrHlCPUTemp    = ".1.3.6.1.4.1.14988.1.1.3.11.0" // desi-°C
	mtxrGaugeName    = ".1.3.6.1.4.1.14988.1.1.3.100.1.2"
	mtxrGaugeValue   = ".1.3.6.1.4.1.14988.1.1.3.100.1.3"
	mtxrGaugeUnit    = ".1.3.6.1.4.1.14988.1.1.3.100.1.4"
	mtxrWlStatTxRate = ".1.3.6.1.4.1.14988.1.1.1.1.1.2"
	mtxrWlStatRxRate = ".1.3.6.1.4.1.14988.1.1.1.1.1.3"
	mtxrWlStatSignal = ".1.3.6.1.4.1.14988.1.1.1.1.1.4"
	mtxrWlStatSsid   = ".1.3.6.1.4.1.14988.1.1.1.1.1.5"
	mtxrWlStatFreq   = ".1.3.6.1.4.1.14988.1.1.1.1.1.7"
	mtxrWlApSsid     = ".1.3.6.1.4.1.14988.1.1.1.3.1.4"
	mtxrWlApClients  = ".1.3.6.1.4.1.14988.1.1.1.3.1.6"
	mtxrWlApFreq     = ".1.3.6.1.4.1.14988.1.1.1.3.1.7"
	mtxrWlApNoise    = ".1.3.6.1.4.1.14988.1.1.1.3.1.9"
	mtxrWlApCCQ      = ".1.3.6.1.4.1.14988.1.1.1.3.1.10"
	mtxrWlRtabSignal = ".1.3.6.1.4.1.14988.1.1.1.2.1.3"
	// mtxrLTEModemTable (mtxrLTEModem = mtXRouterOs 16), indeks = ifIndex interface LTE.
	mtxrLTERSSI = ".1.3.6.1.4.1.14988.1.1.16.1.1.2" // dBm
	mtxrLTERSRQ = ".1.3.6.1.4.1.14988.1.1.16.1.1.3" // dB
	mtxrLTERSRP = ".1.3.6.1.4.1.14988.1.1.16.1.1.4" // dBm
	mtxrLTESINR = ".1.3.6.1.4.1.14988.1.1.16.1.1.7" // dB
	ifName      = ".1.3.6.1.2.1.31.1.1.1.1"
)

func init() {
	register(Profile{ID: "mikrotik", Name: "MikroTik RouterOS", OIDPrefix: []string{".1.3.6.1.4.1.14988"}, Probe: mtxrHlVoltage, Collect: mikrotik})
}

func mikrotik(q Q) []Reading {
	var out []Reading
	s := scalars(q, map[string]string{"volt": mtxrHlVoltage, "temp": mtxrHlTemp, "cpu": mtxrHlCPUTemp})
	if v, ok := num(s["temp"]); ok && v != 0 {
		out = append(out, Reading{Key: "temp:board", Class: "temperature", Label: "Suhu board", Unit: "°C", Value: v / 10, Group: "lingkungan"})
	}
	if v, ok := num(s["cpu"]); ok && v != 0 {
		out = append(out, Reading{Key: "temp:cpu", Class: "temperature", Label: "Suhu CPU", Unit: "°C", Value: v / 10, Group: "lingkungan"})
	}
	if v, ok := num(s["volt"]); ok && v != 0 {
		out = append(out, Reading{Key: "volt:input", Class: "voltage", Label: "Tegangan input", Unit: "V", Value: v / 10, Group: "daya"})
	}

	// RouterOS 7: tabel gauge (nama, nilai, satuan: 1=celsius 2=rpm 3=dV 4=dA 5=dW 6=status)
	g := walks(q, map[string]string{"name": mtxrGaugeName, "value": mtxrGaugeValue, "unit": mtxrGaugeUnit})
	for _, idx := range sortedIdx(g["name"]) {
		name := str(g["name"][idx])
		v, ok := num(g["value"][idx])
		unit, _ := num(g["unit"][idx])
		if !ok || name == "" {
			continue
		}
		var r Reading
		switch int(unit) {
		case 1:
			r = Reading{Class: "temperature", Unit: "°C", Value: v, Group: "lingkungan"}
		case 2:
			r = Reading{Class: "fan", Unit: "rpm", Value: v, Group: "lingkungan"}
		case 3:
			r = Reading{Class: "voltage", Unit: "V", Value: v / 10, Group: "daya"}
		case 4:
			r = Reading{Class: "current", Unit: "A", Value: v / 10, Group: "daya"}
		case 5:
			r = Reading{Class: "power", Unit: "W", Value: v / 10, Group: "daya"}
		default:
			continue
		}
		r.Key, r.Label = r.Class+":"+slug(name), name
		out = append(out, r)
	}

	// Wireless mode station (sisi klien PTP/PTMP)
	st := walks(q, map[string]string{"signal": mtxrWlStatSignal, "tx": mtxrWlStatTxRate, "rx": mtxrWlStatRxRate, "ssid": mtxrWlStatSsid, "freq": mtxrWlStatFreq})
	for _, idx := range sortedIdx(st["signal"]) {
		sig, _ := num(st["signal"][idx])
		if sig == 0 {
			continue // tidak tersambung
		}
		name := str(st["ssid"][idx])
		tag := fmt.Sprintf(" (%s)", name)
		out = append(out, Reading{Key: "wl:signal:" + idx, Class: "signal", Label: "Sinyal" + tag, Unit: "dBm", Value: sig, Group: "radio"})
		if v, ok := num(st["tx"][idx]); ok {
			out = append(out, Reading{Key: "wl:tx:" + idx, Class: "rate", Label: "TX rate" + tag, Unit: "Mbps", Value: math.Round(v/1e5) / 10, Group: "radio"})
		}
		if v, ok := num(st["rx"][idx]); ok {
			out = append(out, Reading{Key: "wl:rx:" + idx, Class: "rate", Label: "RX rate" + tag, Unit: "Mbps", Value: math.Round(v/1e5) / 10, Group: "radio"})
		}
		if v, ok := num(st["freq"][idx]); ok && v > 0 {
			out = append(out, Reading{Key: "wl:freq:" + idx, Class: "frequency", Label: "Frekuensi" + tag, Unit: "MHz", Value: v, Group: "radio"})
		}
	}

	// Wireless mode AP (sisi pemancar PTP/PTMP)
	ap := walks(q, map[string]string{"ssid": mtxrWlApSsid, "clients": mtxrWlApClients, "freq": mtxrWlApFreq, "noise": mtxrWlApNoise, "ccq": mtxrWlApCCQ})
	for _, idx := range sortedIdx(ap["ssid"]) {
		tag := fmt.Sprintf(" (%s)", str(ap["ssid"][idx]))
		if v, ok := num(ap["clients"][idx]); ok {
			out = append(out, Reading{Key: "wl:clients:" + idx, Class: "clients", Label: "Klien" + tag, Unit: "", Value: v, Group: "radio"})
		}
		if v, ok := num(ap["ccq"][idx]); ok && v > 0 {
			out = append(out, Reading{Key: "wl:ccq:" + idx, Class: "ccq", Label: "CCQ" + tag, Unit: "%", Value: v, Group: "radio"})
		}
		if v, ok := num(ap["noise"][idx]); ok && v != 0 {
			out = append(out, Reading{Key: "wl:noise:" + idx, Class: "noise", Label: "Noise floor" + tag, Unit: "dBm", Value: v, Group: "radio"})
		}
		if v, ok := num(ap["freq"][idx]); ok && v > 0 {
			out = append(out, Reading{Key: "wl:freq-ap:" + idx, Class: "frequency", Label: "Frekuensi" + tag, Unit: "MHz", Value: v, Group: "radio"})
		}
	}
	// Modem LTE/4G (router dengan modem bawaan atau USB): kualitas sinyal per interface LTE
	lte := walks(q, map[string]string{"rssi": mtxrLTERSSI, "rsrq": mtxrLTERSRQ, "rsrp": mtxrLTERSRP, "sinr": mtxrLTESINR})
	for _, idx := range sortedIdx(lte["rsrp"]) {
		tag := " LTE"
		if names, err := q.Get([]string{ifName + "." + idx}); err == nil {
			if n := str(names[ifName+"."+idx]); n != "" {
				tag = " (" + n + ")"
			}
		}
		// 0 = modem belum terdaftar ke jaringan / nilai tidak tersedia
		if v, ok := num(lte["rsrp"][idx]); ok && v != 0 {
			out = append(out, Reading{Key: "lte:rsrp:" + idx, Class: "lte_rsrp", Label: "RSRP" + tag, Unit: "dBm", Value: v, Group: "radio"})
		}
		if v, ok := num(lte["rsrq"][idx]); ok && v != 0 {
			out = append(out, Reading{Key: "lte:rsrq:" + idx, Class: "lte_rsrq", Label: "RSRQ" + tag, Unit: "dB", Value: v, Group: "radio"})
		}
		if v, ok := num(lte["sinr"][idx]); ok {
			out = append(out, Reading{Key: "lte:sinr:" + idx, Class: "lte_sinr", Label: "SINR" + tag, Unit: "dB", Value: v, Group: "radio"})
		}
		if v, ok := num(lte["rssi"][idx]); ok && v != 0 {
			out = append(out, Reading{Key: "lte:rssi:" + idx, Class: "lte_rssi", Label: "RSSI" + tag, Unit: "dBm", Value: v, Group: "radio"})
		}
	}

	// Klien terlemah di registration table (sisi AP)
	if rt, _ := q.Walk(mtxrWlRtabSignal); len(rt) > 0 {
		weakest := 0.0
		for _, v := range rt {
			if f, ok := num(v); ok && (weakest == 0 || f < weakest) {
				weakest = f
			}
		}
		if weakest != 0 {
			out = append(out, Reading{Key: "wl:weakest", Class: "signal", Label: fmt.Sprintf("Sinyal klien terlemah (%d klien)", len(rt)), Unit: "dBm", Value: weakest, Group: "radio"})
		}
	}
	return out
}
