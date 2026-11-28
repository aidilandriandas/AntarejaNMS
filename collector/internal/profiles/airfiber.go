package profiles

import (
	"math"
)

// Sumber: UBNT-AirFIBER-MIB (teks MIB Ubiquiti, salinan LibreNMS mibs/ubnt), airFiberStatusTable
// .1.3.6.1.4.1.41112.1.3.2.1, INDEX { airFiberStatusIndex }:
// 4 radioLinkDistM (meter), 5 rxCapacity (bit/s), 6 txCapacity (bit/s), 8 radio0TempC, 11 rxPower0 (dBm),
// 12 rxPower0Valid, 14 rxPower1 (dBm), 15 rxPower1Valid, 19 remoteRXPower0 (dBm), 20 remoteRXPower0Valid,
// 22 remoteRXPower1 (dBm), 23 remoteRXPower1Valid, 26 radioLinkState (0 down, 1 up).
const (
	afStatus      = ".1.3.6.1.4.1.41112.1.3.2.1"
	afDistM       = afStatus + ".4"
	afRxCap       = afStatus + ".5"
	afTxCap       = afStatus + ".6"
	afTemp0C      = afStatus + ".8"
	afRxPow0      = afStatus + ".11"
	afRxPow0Valid = afStatus + ".12"
	afRxPow1      = afStatus + ".14"
	afRxPow1Valid = afStatus + ".15"
	afRmPow0      = afStatus + ".19"
	afRmPow0Valid = afStatus + ".20"
	afRmPow1      = afStatus + ".22"
	afRmPow1Valid = afStatus + ".23"
	afLinkState   = afStatus + ".26"
)

func init() {
	register(Profile{ID: "ubiquiti-airfiber", Name: "Ubiquiti airFiber", OIDPrefix: []string{".1.3.6.1.4.1.41112"}, Probe: afLinkState + ".1", Collect: airfiber})
}

func airfiber(q Q) []Reading {
	var out []Reading
	w := walks(q, map[string]string{"dist": afDistM, "rxc": afRxCap, "txc": afTxCap, "temp": afTemp0C, "p0": afRxPow0, "p0v": afRxPow0Valid,
		"p1": afRxPow1, "p1v": afRxPow1Valid, "r0": afRmPow0, "r0v": afRmPow0Valid, "r1": afRmPow1, "r1v": afRmPow1Valid, "link": afLinkState})
	for _, idx := range sortedIdx(w["link"]) {
		state, _ := num(w["link"][idx])
		if state == 1 {
			out = append(out, Reading{Key: "af:link:" + idx, Class: "link_state", Label: "Link radio", Value: math.NaN(), Text: "tersambung", Status: "ok", Group: "radio"})
		} else {
			out = append(out, Reading{Key: "af:link:" + idx, Class: "link_state", Label: "Link radio", Value: math.NaN(), Text: "putus", Status: "crit", Group: "radio"})
		}
		// rantai yang tidak sah (TruthValue false = 2) dilewati
		chain := func(key, label, col, valid string) {
			v, ok := num(w[col][idx])
			if vv, has := num(w[valid][idx]); has && vv != 1 {
				return
			}
			if ok && v < 0 && v > -120 {
				out = append(out, Reading{Key: key + ":" + idx, Class: "signal", Label: label, Unit: "dBm", Value: v, Group: "radio"})
			}
		}
		chain("af:rx0", "Daya terima rantai 0", "p0", "p0v")
		chain("af:rx1", "Daya terima rantai 1", "p1", "p1v")
		chain("af:rrx0", "Daya terima lawan rantai 0", "r0", "r0v")
		chain("af:rrx1", "Daya terima lawan rantai 1", "r1", "r1v")
		if v, ok := num(w["rxc"][idx]); ok && v > 0 {
			out = append(out, Reading{Key: "af:rxcap:" + idx, Class: "rate", Label: "Kapasitas terima", Unit: "Mbps", Value: math.Round(v/1e5) / 10, Group: "radio"})
		}
		if v, ok := num(w["txc"][idx]); ok && v > 0 {
			out = append(out, Reading{Key: "af:txcap:" + idx, Class: "rate", Label: "Kapasitas kirim", Unit: "Mbps", Value: math.Round(v/1e5) / 10, Group: "radio"})
		}
		if v, ok := num(w["dist"][idx]); ok && v > 0 {
			out = append(out, Reading{Key: "af:dist:" + idx, Class: "distance", Label: "Jarak link", Unit: "km", Value: math.Round(v/100) / 10, Group: "radio"})
		}
		if v, ok := num(w["temp"][idx]); ok && v != 0 {
			out = append(out, Reading{Key: "af:temp:" + idx, Class: "temperature", Label: "Suhu radio", Unit: "°C", Value: v, Group: "lingkungan"})
		}
	}
	return out
}
