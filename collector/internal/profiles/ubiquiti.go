package profiles

import (
	"fmt"
	"math"
)

// Sumber: UBNT-AirMAX-MIB (Ubiquiti airMAX: NanoStation, Rocket, PowerBeam, LiteBeam; enterprise 41112):
// ubntRadioTable (frekuensi, daya TX, jarak), ubntWlStatTable (sinyal, CCQ, noise, rate, jumlah station),
// ubntAirMaxTable (kualitas & kapasitas airMAX).
// VERIFIKASI: satuan TxRate/RxRate (kbps) dan keberadaan tabel airMAX pada firmware yang dipakai.

const (
	ubntRadioFreq    = ".1.3.6.1.4.1.41112.1.4.1.1.4"
	ubntRadioTxPower = ".1.3.6.1.4.1.41112.1.4.1.1.6"
	ubntRadioDist    = ".1.3.6.1.4.1.41112.1.4.1.1.7"
	ubntWlSsid       = ".1.3.6.1.4.1.41112.1.4.5.1.2"
	ubntWlSignal     = ".1.3.6.1.4.1.41112.1.4.5.1.5"
	ubntWlCCQ        = ".1.3.6.1.4.1.41112.1.4.5.1.7"
	ubntWlNoise      = ".1.3.6.1.4.1.41112.1.4.5.1.8"
	ubntWlTxRate     = ".1.3.6.1.4.1.41112.1.4.5.1.9"
	ubntWlRxRate     = ".1.3.6.1.4.1.41112.1.4.5.1.10"
	ubntWlStaCount   = ".1.3.6.1.4.1.41112.1.4.5.1.15"
	ubntAMQuality    = ".1.3.6.1.4.1.41112.1.4.6.1.3"
	ubntAMCapacity   = ".1.3.6.1.4.1.41112.1.4.6.1.4"
)

func init() {
	register(Profile{ID: "ubiquiti-airmax", Name: "Ubiquiti airMAX", OIDPrefix: []string{".1.3.6.1.4.1.41112"}, Probe: ubntWlSignal + ".1", Collect: ubiquiti})
}

func ubiquiti(q Q) []Reading {
	var out []Reading
	w := walks(q, map[string]string{"ssid": ubntWlSsid, "signal": ubntWlSignal, "ccq": ubntWlCCQ, "noise": ubntWlNoise, "tx": ubntWlTxRate, "rx": ubntWlRxRate, "sta": ubntWlStaCount})
	r := walks(q, map[string]string{"freq": ubntRadioFreq, "txp": ubntRadioTxPower, "dist": ubntRadioDist})
	am := walks(q, map[string]string{"q": ubntAMQuality, "c": ubntAMCapacity})
	for _, idx := range sortedIdx(w["signal"]) {
		tag := ""
		if s := str(w["ssid"][idx]); s != "" {
			tag = fmt.Sprintf(" (%s)", s)
		}
		add := func(key, class, label, unit string, v any, scale float64, skipZero bool) {
			if f, ok := num(v); ok && !(skipZero && f == 0) {
				out = append(out, Reading{Key: key + ":" + idx, Class: class, Label: label + tag, Unit: unit, Value: math.Round(f*scale*10) / 10, Group: "radio"})
			}
		}
		add("wl:signal", "signal", "Sinyal", "dBm", w["signal"][idx], 1, true)
		add("wl:ccq", "ccq", "CCQ", "%", w["ccq"][idx], 1, true)
		add("wl:noise", "noise", "Noise floor", "dBm", w["noise"][idx], 1, true)
		add("wl:tx", "rate", "TX rate", "Mbps", w["tx"][idx], 0.001, true)
		add("wl:rx", "rate", "RX rate", "Mbps", w["rx"][idx], 0.001, true)
		add("wl:clients", "clients", "Station", "", w["sta"][idx], 1, false)
		add("wl:quality", "quality", "airMAX quality", "%", am["q"][idx], 1, true)
		add("wl:capacity", "capacity", "airMAX capacity", "%", am["c"][idx], 1, true)
		add("wl:freq", "frequency", "Frekuensi", "MHz", r["freq"][idx], 1, true)
		add("wl:txpower", "txpower", "Daya TX", "dBm", r["txp"][idx], 1, false)
		if d, ok := num(r["dist"][idx]); ok && d > 0 {
			out = append(out, Reading{Key: "wl:distance:" + idx, Class: "distance", Label: "Jarak link" + tag, Unit: "km", Value: math.Round(d/100) / 10, Group: "radio"})
		}
	}
	return out
}
