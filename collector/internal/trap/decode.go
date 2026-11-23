// Package trap menerima SNMP trap v1/v2c lewat UDP, mengenali trap umum, menyimpannya, dan memicu
// pembacaan SNMP segera untuk trap link/UPS supaya status di NMS tidak menunggu putaran polling.
package trap

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/gosnmp/gosnmp"
)

const (
	oidSnmpTrapOID = ".1.3.6.1.6.3.1.1.4.1.0"
	oidSysUptime   = ".1.3.6.1.2.1.1.3.0"
	oidIfIndex     = ".1.3.6.1.2.1.2.2.1.1."
	oidIfDescr     = ".1.3.6.1.2.1.2.2.1.2."
	oidIfName      = ".1.3.6.1.2.1.31.1.1.1.1."
)

// Known trap standar (SNMPv2-MIB, IF-MIB, UPS-MIB RFC 1628).
type Known struct {
	Name     string
	Severity string
	Text     string // ringkasan; %s diganti nama port bila ada
	Poll     bool   // picu pembacaan SNMP segera
}

var known = map[string]Known{
	".1.3.6.1.6.3.1.1.5.1": {"coldStart", "warning", "Perangkat menyala dari mati (coldStart)", true},
	".1.3.6.1.6.3.1.1.5.2": {"warmStart", "info", "Perangkat memulai ulang agen SNMP (warmStart)", true},
	".1.3.6.1.6.3.1.1.5.3": {"linkDown", "average", "Port %s putus (linkDown)", true},
	".1.3.6.1.6.3.1.1.5.4": {"linkUp", "info", "Port %s tersambung (linkUp)", true},
	".1.3.6.1.6.3.1.1.5.5": {"authenticationFailure", "warning", "Ada yang mencoba SNMP dengan community atau user yang salah (authenticationFailure)", false},
	".1.3.6.1.2.1.33.2.1":  {"upsTrapOnBattery", "high", "UPS beralih ke baterai (upsTrapOnBattery)", true},
	".1.3.6.1.2.1.33.2.2":  {"upsTrapTestCompleted", "info", "Uji baterai UPS selesai (upsTrapTestCompleted)", true},
	".1.3.6.1.2.1.33.2.3":  {"upsTrapAlarmEntryAdded", "average", "UPS memunculkan alarm (upsTrapAlarmEntryAdded)", true},
	".1.3.6.1.2.1.33.2.4":  {"upsTrapAlarmEntryRemoved", "info", "Alarm UPS hilang (upsTrapAlarmEntryRemoved)", true},
}

// v1 generic-trap ke OID SNMPv2 (RFC 3584).
var genericV1 = map[int]string{0: ".1.3.6.1.6.3.1.1.5.1", 1: ".1.3.6.1.6.3.1.1.5.2", 2: ".1.3.6.1.6.3.1.1.5.3",
	3: ".1.3.6.1.6.3.1.1.5.4", 4: ".1.3.6.1.6.3.1.1.5.5", 5: ".1.3.6.1.6.3.1.1.5.6"}

// Varbind satu pasangan OID dan nilai untuk disimpan.
type Varbind struct {
	OID   string `json:"oid"`
	Value string `json:"value"`
}

// Decoded hasil penguraian satu trap.
type Decoded struct {
	Version  string
	OID      string
	Name     string
	Severity string
	Summary  string
	IfIndex  *int
	Port     string // nama port dari varbind (kosong bila tidak ada)
	Poll     bool
	Varbinds []Varbind
}

// Decode menguraikan paket trap. portName dipakai bila trap link tidak membawa nama port (boleh nil).
func Decode(p *gosnmp.SnmpPacket, portName func(ifIndex int) string) Decoded {
	d := Decoded{Severity: "info", Varbinds: []Varbind{}}
	switch p.Version {
	case gosnmp.Version1:
		d.Version = "1"
		if oid, ok := genericV1[p.GenericTrap]; ok {
			d.OID = oid
		} else {
			d.OID = strings.TrimSuffix(p.Enterprise, ".") + ".0." + strconv.Itoa(p.SpecificTrap)
			if !strings.HasPrefix(d.OID, ".") {
				d.OID = "." + d.OID
			}
		}
	default:
		d.Version = "2c"
	}
	for _, v := range p.Variables {
		val := value(v)
		switch {
		case v.Name == oidSnmpTrapOID:
			d.OID = val
			if !strings.HasPrefix(d.OID, ".") {
				d.OID = "." + d.OID
			}
			continue
		case v.Name == oidSysUptime:
			continue
		case strings.HasPrefix(v.Name, oidIfIndex):
			if n, err := strconv.Atoi(val); err == nil {
				d.IfIndex = &n
			}
		case strings.HasPrefix(v.Name, oidIfName), strings.HasPrefix(v.Name, oidIfDescr):
			if d.Port == "" || strings.HasPrefix(v.Name, oidIfName) {
				d.Port = val
			}
		}
		if len(d.Varbinds) < 30 {
			if len(val) > 200 {
				val = val[:200] + "…"
			}
			d.Varbinds = append(d.Varbinds, Varbind{OID: v.Name, Value: strings.ToValidUTF8(val, "?")})
		}
	}
	if d.OID == "" {
		d.OID = "tidak diketahui"
	}
	k, ok := known[d.OID]
	if !ok {
		d.Summary = "Trap " + d.OID
		if len(d.Varbinds) > 0 {
			d.Summary += ": " + d.Varbinds[0].Value
		}
		d.Summary = trim(d.Summary, 500)
		return d
	}
	d.Name, d.Severity, d.Poll = k.Name, k.Severity, k.Poll
	if strings.Contains(k.Text, "%s") {
		port := d.Port
		if port == "" && d.IfIndex != nil && portName != nil {
			port = portName(*d.IfIndex)
		}
		if port == "" && d.IfIndex != nil {
			port = "#" + strconv.Itoa(*d.IfIndex)
		}
		if port == "" {
			port = "(tidak disebut)"
		}
		d.Port = port
		d.Summary = fmt.Sprintf(k.Text, port)
	} else {
		d.Summary = k.Text
	}
	d.Summary = trim(d.Summary, 500)
	return d
}

func value(v gosnmp.SnmpPDU) string {
	switch v.Type {
	case gosnmp.OctetString:
		b, _ := v.Value.([]byte)
		return printable(b)
	case gosnmp.ObjectIdentifier, gosnmp.IPAddress:
		return fmt.Sprint(v.Value)
	case gosnmp.Null, gosnmp.NoSuchObject, gosnmp.NoSuchInstance, gosnmp.EndOfMibView:
		return ""
	}
	return gosnmp.ToBigInt(v.Value).String()
}

// printable: teks bila bisa dibaca, selain itu heksadesimal (mis. MAC).
func printable(b []byte) string {
	for _, c := range b {
		if (c < 0x20 && c != '\t' && c != '\n' && c != '\r') || c == 0x7f {
			parts := make([]string, len(b))
			for i, x := range b {
				parts[i] = fmt.Sprintf("%02x", x)
			}
			return strings.Join(parts, ":")
		}
	}
	return strings.TrimSpace(string(b))
}

func trim(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return strings.ToValidUTF8(s[:n], "")
}
