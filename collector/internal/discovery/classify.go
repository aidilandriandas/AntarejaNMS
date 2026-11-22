// Package discovery memindai subnet atas permintaan engineer dari web: ping lalu SNMP GET grup system (HANYA BACA).
// Hasilnya berupa USULAN; perangkat baru ditambahkan hanya setelah engineer memilih dan menyetujuinya di web.
package discovery

import (
	"regexp"
	"strings"
)

// vendor per nomor enterprise IANA (awalan sysObjectID .1.3.6.1.4.1.N). Tebakan; engineer meninjau di web.
var vendors = map[string]string{
	"9": "Cisco", "14988": "MikroTik", "41112": "Ubiquiti", "10002": "Ubiquiti", "39165": "Hikvision",
	"318": "APC", "534": "Eaton", "8072": "Net-SNMP", "311": "Microsoft", "2636": "Juniper", "12356": "Fortinet",
	"25461": "Palo Alto", "11": "HP", "25506": "H3C", "2011": "Huawei", "17713": "Cambium", "161": "Cambium",
	"30065": "Arista", "1916": "Extreme", "1588": "Brocade", "14823": "Aruba", "25053": "Ruckus", "14179": "Cisco WLC",
	"11863": "TP-Link", "171": "D-Link", "4526": "Netgear", "3902": "ZTE", "674": "Dell", "6876": "VMware",
}

var (
	reMTRadio  = regexp.MustCompile(`(?i)\b(wAP|SXT|LHG|LDF|Disc|mANTBox|NetMetal|BaseBox|QRT|NetBox|Cube)\b`)
	reMTSwitch = regexp.MustCompile(`(?i)\b(CRS|CSS)\d`)
	reNVR      = regexp.MustCompile(`(?i)\b(NVR|DVR|DS-7|DS-9|iDS-7|XVR)`)
	reFirewall = regexp.MustCompile(`(?i)(Adaptive Security Appliance|\bASA\b|Firepower|FortiGate|pfSense|OPNsense|Sophos|PAN-OS)`)
	reCiscoAP  = regexp.MustCompile(`(?i)(Aironet|\bAIR-|Catalyst 91\d\d)`)
	reCiscoRtr = regexp.MustCompile(`(?i)(\bISR\b|\bASR\b|C1100|C8[0-9]00|Router)`)
	reCiscoSw  = regexp.MustCompile(`(?i)(Catalyst|\bC9[2-6]00|C2960|C3[6-8]50|Switch|IE-\d)`)
	reUniFiAP  = regexp.MustCompile(`(?i)(UAP|\bU6|\bU7|UniFi AP)`)
	reUPS      = regexp.MustCompile(`(?i)\bUPS\b`)
	reServer   = regexp.MustCompile(`(?i)(\bLinux\b|Windows|VMware ESX|FreeBSD|Proxmox)`)
)

// Classify menebak vendor & kategori perangkat dari sysObjectID dan sysDescr.
func Classify(objectID, descr, name string) (vendor, category string) {
	ent := ""
	if rest, ok := strings.CutPrefix(strings.TrimPrefix(objectID, "."), "1.3.6.1.4.1."); ok {
		ent, _, _ = strings.Cut(rest, ".")
	}
	vendor = vendors[ent]
	text := descr + " " + name
	switch {
	case strings.HasPrefix(strings.TrimPrefix(objectID, "."), "1.3.6.1.2.1.33") || ent == "318" || ent == "534" ||
		reUPS.MatchString(descr):
		return vendor, "ups"
	case ent == "39165" || strings.Contains(strings.ToLower(text), "hikvision") || strings.Contains(strings.ToLower(text), "dahua"):
		if vendor == "" {
			if strings.Contains(strings.ToLower(text), "dahua") {
				vendor = "Dahua"
			} else {
				vendor = "Hikvision"
			}
		}
		if reNVR.MatchString(text) {
			return vendor, "nvr"
		}
		return vendor, "cctv"
	case reFirewall.MatchString(text) || ent == "12356" || ent == "25461":
		return vendor, "firewall"
	case ent == "14988" || strings.Contains(descr, "RouterOS"):
		if vendor == "" {
			vendor = "MikroTik"
		}
		switch {
		case reMTRadio.MatchString(text):
			return vendor, "ptp"
		case reMTSwitch.MatchString(text):
			return vendor, "access"
		}
		return vendor, "distribution"
	case ent == "41112" || ent == "10002":
		if reUniFiAP.MatchString(text) {
			return vendor, "wireless"
		}
		if strings.Contains(strings.ToLower(text), "edgeswitch") {
			return vendor, "access"
		}
		return vendor, "ptp"
	case ent == "17713" || ent == "161":
		return vendor, "ptp"
	case ent == "14823" || ent == "25053" || ent == "14179":
		return vendor, "wireless"
	case ent == "9" || strings.Contains(descr, "Cisco"):
		if vendor == "" {
			vendor = "Cisco"
		}
		switch {
		case reCiscoAP.MatchString(descr):
			return vendor, "wireless"
		case reCiscoRtr.MatchString(descr):
			return vendor, "core"
		case reCiscoSw.MatchString(descr):
			return vendor, "access"
		}
		return vendor, "distribution"
	case ent == "2636" || ent == "30065" || ent == "11" || ent == "25506" || ent == "2011" || ent == "1916" ||
		ent == "1588" || ent == "11863" || ent == "171" || ent == "4526" || ent == "3902":
		return vendor, "access"
	case ent == "311" || ent == "674" || ent == "6876" || ent == "8072" || reServer.MatchString(descr):
		return vendor, "server"
	}
	return vendor, "other"
}
