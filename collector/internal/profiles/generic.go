package profiles

import (
	"fmt"
	"math"
	"strings"
)

// Sumber: HOST-RESOURCES-MIB (RFC 2790), UCD-SNMP-MIB (net-snmp, memori Linux), ENTITY-SENSOR-MIB (RFC 3433),
// ENTITY-MIB (RFC 4133, nama sensor). Berlaku untuk semua perangkat yang menjawab.

const (
	hrProcessorLoad = ".1.3.6.1.2.1.25.3.3.1.2"
	hrStorageType   = ".1.3.6.1.2.1.25.2.3.1.2"
	hrStorageDescr  = ".1.3.6.1.2.1.25.2.3.1.3"
	hrStorageUnits  = ".1.3.6.1.2.1.25.2.3.1.4"
	hrStorageSize   = ".1.3.6.1.2.1.25.2.3.1.5"
	hrStorageUsed   = ".1.3.6.1.2.1.25.2.3.1.6"
	hrStorageRam    = ".1.3.6.1.2.1.25.2.1.2"
	hrStorageDisk   = ".1.3.6.1.2.1.25.2.1.4"

	ucdMemTotal  = ".1.3.6.1.4.1.2021.4.5.0"
	ucdMemAvail  = ".1.3.6.1.4.1.2021.4.6.0"
	ucdMemBuffer = ".1.3.6.1.4.1.2021.4.14.0"
	ucdMemCached = ".1.3.6.1.4.1.2021.4.15.0"

	entSensorType  = ".1.3.6.1.2.1.99.1.1.1.1"
	entSensorScale = ".1.3.6.1.2.1.99.1.1.1.2"
	entSensorPrec  = ".1.3.6.1.2.1.99.1.1.1.3"
	entSensorValue = ".1.3.6.1.2.1.99.1.1.1.4"
	entSensorOper  = ".1.3.6.1.2.1.99.1.1.1.5"
	entPhysName    = ".1.3.6.1.2.1.47.1.1.1.1.7"
)

func init() {
	register(Profile{ID: "host-resources", Name: "HOST-RESOURCES (CPU, memori, disk)", Generic: true, Collect: hostResources})
	register(Profile{ID: "entity-sensor", Name: "ENTITY-SENSOR (suhu, tegangan, kipas)", Generic: true, Collect: entitySensors})
}

var skipDisk = []string{"/run", "/dev", "/sys", "/proc", "/snap", "/var/lib/docker", "/boot/efi", "/etc"} // /etc/* = berkas bind-mount container

func hostResources(q Q) []Reading {
	var out []Reading
	if loads, _ := q.Walk(hrProcessorLoad); len(loads) > 0 {
		var sum float64
		var n int
		for _, v := range loads {
			if f, ok := num(v); ok {
				sum += f
				n++
			}
		}
		if n > 0 {
			out = append(out, Reading{Key: "cpu", Class: "cpu", Label: fmt.Sprintf("CPU (rata-rata %d inti)", n), Unit: "%", Value: math.Round(sum/float64(n)*10) / 10, Group: "sistem"})
		}
	}

	// Memori: utamakan UCD (Linux) karena hrStorage RAM ikut menghitung buffer/cache
	u := scalars(q, map[string]string{"total": ucdMemTotal, "avail": ucdMemAvail, "buf": ucdMemBuffer, "cache": ucdMemCached})
	if total, ok := num(u["total"]); ok && total > 0 {
		avail, _ := num(u["avail"])
		buf, _ := num(u["buf"])
		cache, _ := num(u["cache"])
		used := math.Max(0, total-avail-buf-cache)
		out = append(out, Reading{Key: "memory", Class: "memory", Label: "Memori", Unit: "%", Value: math.Round(used/total*1000) / 10, Group: "sistem"})
	}

	t := walks(q, map[string]string{"type": hrStorageType, "descr": hrStorageDescr, "units": hrStorageUnits, "size": hrStorageSize, "used": hrStorageUsed})
	disks := 0
	for _, idx := range sortedIdx(t["type"]) {
		typ := str(t["type"][idx])
		size, _ := num(t["size"][idx])
		used, _ := num(t["used"][idx])
		if size <= 0 {
			continue
		}
		pct := math.Round(used/size*1000) / 10
		descr := str(t["descr"][idx])
		switch typ {
		case hrStorageRam:
			if !hasKey(out, "memory") {
				out = append(out, Reading{Key: "memory", Class: "memory", Label: "Memori", Unit: "%", Value: pct, Group: "sistem"})
			}
		case hrStorageDisk:
			if disks >= 8 || skippable(descr) {
				continue
			}
			disks++
			out = append(out, Reading{Key: "disk:" + slug(descr), Class: "disk", Label: "Disk " + descr, Unit: "%", Value: pct, Group: "sistem"})
		}
	}
	return out
}

func skippable(d string) bool {
	for _, p := range skipDisk {
		if d == p || strings.HasPrefix(d, p+"/") {
			return true
		}
	}
	return false
}

func hasKey(rs []Reading, k string) bool {
	for _, r := range rs {
		if r.Key == k {
			return true
		}
	}
	return false
}

// entPhySensorType → kelas & satuan
var entTypes = map[int][2]string{8: {"temperature", "°C"}, 3: {"voltage", "V AC"}, 4: {"voltage", "V"}, 10: {"fan", "rpm"}, 6: {"power", "W"}, 5: {"current", "A"}}

func entitySensors(q Q) []Reading {
	t := walks(q, map[string]string{"type": entSensorType})
	if len(t["type"]) == 0 {
		return nil
	}
	t2 := walks(q, map[string]string{"scale": entSensorScale, "prec": entSensorPrec, "value": entSensorValue, "oper": entSensorOper, "name": entPhysName})
	var out []Reading
	for _, idx := range sortedIdx(t["type"]) {
		typ, _ := num(t["type"][idx])
		meta, ok := entTypes[int(typ)]
		if !ok || len(out) >= 24 {
			continue
		}
		if oper, ok := num(t2["oper"][idx]); ok && oper != 1 {
			continue // sensor tidak tersedia / tidak berfungsi
		}
		raw, ok := num(t2["value"][idx])
		if !ok {
			continue
		}
		scale, _ := num(t2["scale"][idx])
		prec, _ := num(t2["prec"][idx])
		if scale == 0 {
			scale = 9 // units
		}
		v := raw * math.Pow(10, (scale-9)*3) / math.Pow(10, prec)
		name := str(t2["name"][idx])
		if name == "" {
			name = "sensor " + idx
		}
		out = append(out, Reading{Key: meta[0] + ":ent" + idx, Class: meta[0], Label: name, Unit: meta[1], Value: math.Round(v*100) / 100, Group: "lingkungan"})
	}
	return out
}
