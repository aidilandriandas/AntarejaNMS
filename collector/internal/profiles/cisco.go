package profiles

import (
	"math"
	"strings"
)

// Sumber: CISCO-PROCESS-MIB (cpmCPUTotal5minRev), CISCO-MEMORY-POOL-MIB (ciscoMemoryPoolTable),
// CISCO-ENVMON-MIB (ciscoEnvMonTemperatureStatusTable). Switch/router Cisco IOS/IOS-XE.

const (
	cpmCPU5min    = ".1.3.6.1.4.1.9.9.109.1.1.1.1.8"
	ciscoPoolName = ".1.3.6.1.4.1.9.9.48.1.1.1.2"
	ciscoPoolUsed = ".1.3.6.1.4.1.9.9.48.1.1.1.5"
	ciscoPoolFree = ".1.3.6.1.4.1.9.9.48.1.1.1.6"
	ciscoTempDesc = ".1.3.6.1.4.1.9.9.13.1.3.1.2"
	ciscoTempVal  = ".1.3.6.1.4.1.9.9.13.1.3.1.3"
)

func init() {
	register(Profile{ID: "cisco", Name: "Cisco IOS / IOS-XE", OIDPrefix: []string{".1.3.6.1.4.1.9.1", ".1.3.6.1.4.1.9.12"}, Collect: cisco})
}

func cisco(q Q) []Reading {
	var out []Reading
	if cpu, _ := q.Walk(cpmCPU5min); len(cpu) > 0 {
		var sum float64
		for _, v := range cpu {
			f, _ := num(v)
			sum += f
		}
		out = append(out, Reading{Key: "cpu", Class: "cpu", Label: "CPU (5 menit)", Unit: "%", Value: math.Round(sum/float64(len(cpu))*10) / 10, Group: "sistem"})
	}
	p := walks(q, map[string]string{"name": ciscoPoolName, "used": ciscoPoolUsed, "free": ciscoPoolFree})
	for _, idx := range sortedIdx(p["name"]) {
		if !strings.EqualFold(str(p["name"][idx]), "processor") {
			continue
		}
		u, _ := num(p["used"][idx])
		f, _ := num(p["free"][idx])
		if u+f > 0 {
			out = append(out, Reading{Key: "memory", Class: "memory", Label: "Memori (processor pool)", Unit: "%", Value: math.Round(u/(u+f)*1000) / 10, Group: "sistem"})
		}
	}
	t := walks(q, map[string]string{"desc": ciscoTempDesc, "val": ciscoTempVal})
	for _, idx := range sortedIdx(t["val"]) {
		if v, ok := num(t["val"][idx]); ok && v > 0 {
			name := str(t["desc"][idx])
			if name == "" {
				name = "Suhu " + idx
			}
			out = append(out, Reading{Key: "temp:cisco" + idx, Class: "temperature", Label: name, Unit: "°C", Value: v, Group: "lingkungan"})
		}
	}
	return out
}
