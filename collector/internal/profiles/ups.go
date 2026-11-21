package profiles

import (
	"math"
	"strconv"
)

// Sumber: UPS-MIB (RFC 1628) untuk UPS dengan kartu SNMP standar, dan PowerNet-MIB (APC, enterprise 318).

const (
	upsBatteryStatus = ".1.3.6.1.2.1.33.1.2.1.0"
	upsSecondsOnBatt = ".1.3.6.1.2.1.33.1.2.2.0"
	upsMinutesRemain = ".1.3.6.1.2.1.33.1.2.3.0"
	upsChargeRemain  = ".1.3.6.1.2.1.33.1.2.4.0"
	upsBatteryTemp   = ".1.3.6.1.2.1.33.1.2.7.0"
	upsInputVoltage  = ".1.3.6.1.2.1.33.1.3.3.1.3"
	upsOutputSource  = ".1.3.6.1.2.1.33.1.4.1.0"
	upsOutputLoad    = ".1.3.6.1.2.1.33.1.4.4.1.5"

	apcOutputStatus = ".1.3.6.1.4.1.318.1.1.1.4.1.1.0"
	apcBattCapacity = ".1.3.6.1.4.1.318.1.1.1.2.2.1.0"
	apcBattTemp     = ".1.3.6.1.4.1.318.1.1.1.2.2.2.0"
	apcRunTime      = ".1.3.6.1.4.1.318.1.1.1.2.2.3.0" // TimeTicks (1/100 detik)
	apcOutputLoad   = ".1.3.6.1.4.1.318.1.1.1.4.2.3.0"
	apcInputVoltage = ".1.3.6.1.4.1.318.1.1.1.3.2.1.0"
)

func init() {
	register(Profile{ID: "ups-mib", Name: "UPS (UPS-MIB RFC 1628)", Probe: upsBatteryStatus, Collect: upsMIB})
	register(Profile{ID: "apc", Name: "APC Smart-UPS (PowerNet)", OIDPrefix: []string{".1.3.6.1.4.1.318"}, Probe: apcBattCapacity, Collect: apc})
}

var upsSource = map[int]string{1: "lainnya", 2: "tidak ada output", 3: "normal (listrik PLN/genset)", 4: "bypass", 5: "BATERAI", 6: "booster", 7: "reducer"}
var upsBattery = map[int]string{1: "tidak diketahui", 2: "normal", 3: "LEMAH", 4: "HABIS"}

func upsMIB(q Q) []Reading {
	s := scalars(q, map[string]string{"bstat": upsBatteryStatus, "onbatt": upsSecondsOnBatt, "min": upsMinutesRemain, "charge": upsChargeRemain,
		"temp": upsBatteryTemp, "src": upsOutputSource})
	var out []Reading
	if v, ok := num(s["src"]); ok {
		st := "ok"
		switch int(v) {
		case 5:
			st = "crit"
		case 2, 4:
			st = "warn"
		}
		text := upsSource[int(v)]
		if int(v) == 5 {
			if sec, ok := num(s["onbatt"]); ok && sec > 0 {
				text += " sejak " + strconv.Itoa(int(sec/60)) + " menit"
			}
		}
		out = append(out, Reading{Key: "ups:source", Class: "ups_status", Label: "Sumber daya output", Value: v, Text: text, Status: st, Group: "daya"})
	}
	if v, ok := num(s["bstat"]); ok {
		st := map[int]string{3: "warn", 4: "crit"}[int(v)]
		if st == "" {
			st = "ok"
		}
		out = append(out, Reading{Key: "ups:battery-status", Class: "ups_status", Label: "Status baterai", Value: v, Text: upsBattery[int(v)], Status: st, Group: "daya"})
	}
	if v, ok := num(s["charge"]); ok {
		out = append(out, Reading{Key: "ups:charge", Class: "battery", Label: "Muatan baterai", Unit: "%", Value: v, Group: "daya"})
	}
	if v, ok := num(s["min"]); ok {
		out = append(out, Reading{Key: "ups:runtime", Class: "runtime", Label: "Perkiraan waktu cadangan", Unit: "menit", Value: v, Group: "daya"})
	}
	if v, ok := num(s["temp"]); ok && v > 0 {
		out = append(out, Reading{Key: "temp:ups-battery", Class: "temperature", Label: "Suhu baterai UPS", Unit: "°C", Value: v, Group: "lingkungan"})
	}
	if in, _ := q.Walk(upsInputVoltage); len(in) > 0 {
		for i, idx := range sortedIdx(in) {
			if v, ok := num(in[idx]); ok {
				label := "Tegangan input"
				if len(in) > 1 {
					label += " fasa " + strconv.Itoa(i+1)
				}
				out = append(out, Reading{Key: "volt:ups-in" + idx, Class: "voltage", Label: label, Unit: "V", Value: v, Group: "daya"})
			}
		}
	}
	if ld, _ := q.Walk(upsOutputLoad); len(ld) > 0 {
		maxLoad := 0.0
		for _, v := range ld {
			if f, ok := num(v); ok {
				maxLoad = math.Max(maxLoad, f)
			}
		}
		out = append(out, Reading{Key: "ups:load", Class: "load", Label: "Beban output", Unit: "%", Value: maxLoad, Group: "daya"})
	}
	return out
}

func apc(q Q) []Reading {
	s := scalars(q, map[string]string{"stat": apcOutputStatus, "cap": apcBattCapacity, "temp": apcBattTemp, "rt": apcRunTime, "load": apcOutputLoad, "vin": apcInputVoltage})
	var out []Reading
	if v, ok := num(s["stat"]); ok {
		text, st := map[int]string{2: "normal (online)", 3: "BATERAI", 4: "smart boost", 5: "timed sleeping", 6: "software bypass", 7: "mati", 8: "rebooting", 9: "switched bypass", 10: "hardware failure bypass", 12: "smart trim"}[int(v)], "ok"
		switch int(v) {
		case 3, 7, 10:
			st = "crit"
		case 6, 9:
			st = "warn"
		}
		out = append(out, Reading{Key: "ups:source", Class: "ups_status", Label: "Status output", Value: v, Text: text, Status: st, Group: "daya"})
	}
	if v, ok := num(s["cap"]); ok {
		out = append(out, Reading{Key: "ups:charge", Class: "battery", Label: "Muatan baterai", Unit: "%", Value: v, Group: "daya"})
	}
	if v, ok := num(s["rt"]); ok {
		out = append(out, Reading{Key: "ups:runtime", Class: "runtime", Label: "Perkiraan waktu cadangan", Unit: "menit", Value: math.Round(v / 6000), Group: "daya"})
	}
	if v, ok := num(s["load"]); ok {
		out = append(out, Reading{Key: "ups:load", Class: "load", Label: "Beban output", Unit: "%", Value: v, Group: "daya"})
	}
	if v, ok := num(s["vin"]); ok {
		out = append(out, Reading{Key: "volt:ups-in", Class: "voltage", Label: "Tegangan input", Unit: "V", Value: v, Group: "daya"})
	}
	if v, ok := num(s["temp"]); ok && v > 0 {
		out = append(out, Reading{Key: "temp:ups-battery", Class: "temperature", Label: "Suhu baterai UPS", Unit: "°C", Value: v, Group: "lingkungan"})
	}
	return out
}
