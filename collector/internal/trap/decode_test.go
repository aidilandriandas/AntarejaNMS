package trap

import (
	"testing"

	"github.com/gosnmp/gosnmp"
)

// bolak-balik lewat encoder gosnmp supaya penguraian diuji pada byte trap sungguhan
func roundTrip(t *testing.T, version gosnmp.SnmpVersion, tr gosnmp.SnmpTrap) *gosnmp.SnmpPacket {
	t.Helper()
	pkt := &gosnmp.SnmpPacket{Version: version, Community: "public", PDUType: gosnmp.SNMPv2Trap, SnmpTrap: tr, Variables: tr.Variables}
	if version == gosnmp.Version1 {
		pkt.PDUType = gosnmp.Trap
	}
	b, err := pkt.MarshalMsg()
	if err != nil {
		t.Fatal(err)
	}
	out, err := (&gosnmp.GoSNMP{}).UnmarshalTrap(b, false)
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func TestDecodeV2LinkDown(t *testing.T) {
	p := roundTrip(t, gosnmp.Version2c, gosnmp.SnmpTrap{Variables: []gosnmp.SnmpPDU{
		{Name: ".1.3.6.1.2.1.1.3.0", Type: gosnmp.TimeTicks, Value: uint32(1234)},
		{Name: ".1.3.6.1.6.3.1.1.4.1.0", Type: gosnmp.ObjectIdentifier, Value: ".1.3.6.1.6.3.1.1.5.3"},
		{Name: ".1.3.6.1.2.1.2.2.1.1.3", Type: gosnmp.Integer, Value: 3},
		{Name: ".1.3.6.1.2.1.2.2.1.7.3", Type: gosnmp.Integer, Value: 1},
	}})
	d := Decode(p, func(i int) string { return map[int]string{3: "ether3"}[i] })
	if d.Name != "linkDown" || d.Severity != "average" || d.IfIndex == nil || *d.IfIndex != 3 || d.Summary != "Port ether3 putus (linkDown)" || !d.Poll || d.Version != "2c" {
		t.Fatalf("%+v", d)
	}
	if len(d.Varbinds) != 2 {
		t.Fatalf("snmpTrapOID dan sysUpTime tidak perlu disimpan ulang: %+v", d.Varbinds)
	}
}

func TestDecodeV1(t *testing.T) {
	p := roundTrip(t, gosnmp.Version1, gosnmp.SnmpTrap{Enterprise: ".1.3.6.1.4.1.14988", AgentAddress: "10.0.0.1", GenericTrap: 3,
		Variables: []gosnmp.SnmpPDU{{Name: ".1.3.6.1.2.1.2.2.1.1.5", Type: gosnmp.Integer, Value: 5}, {Name: ".1.3.6.1.2.1.31.1.1.1.1.5", Type: gosnmp.OctetString, Value: []byte("sfp1")}}})
	d := Decode(p, nil)
	if d.Name != "linkUp" || d.Summary != "Port sfp1 tersambung (linkUp)" || d.Version != "1" {
		t.Fatalf("%+v", d)
	}
	p = roundTrip(t, gosnmp.Version1, gosnmp.SnmpTrap{Enterprise: ".1.3.6.1.4.1.39165", AgentAddress: "10.0.0.9", GenericTrap: 6, SpecificTrap: 7,
		Variables: []gosnmp.SnmpPDU{{Name: ".1.3.6.1.4.1.39165.1.1", Type: gosnmp.OctetString, Value: []byte("HDD error")}}})
	d = Decode(p, nil)
	if d.OID != ".1.3.6.1.4.1.39165.0.7" || d.Name != "" || d.Severity != "info" || d.Summary != "Trap .1.3.6.1.4.1.39165.0.7: HDD error" || d.Poll {
		t.Fatalf("%+v", d)
	}
}

func TestDecodeUPSAndBinary(t *testing.T) {
	p := roundTrip(t, gosnmp.Version2c, gosnmp.SnmpTrap{Variables: []gosnmp.SnmpPDU{
		{Name: ".1.3.6.1.6.3.1.1.4.1.0", Type: gosnmp.ObjectIdentifier, Value: ".1.3.6.1.2.1.33.2.1"},
		{Name: ".1.3.6.1.4.1.9.9.1", Type: gosnmp.OctetString, Value: []byte{0x00, 0x1b, 0xff}},
	}})
	d := Decode(p, nil)
	if d.Name != "upsTrapOnBattery" || d.Severity != "high" || !d.Poll || d.Varbinds[0].Value != "00:1b:ff" {
		t.Fatalf("%+v", d)
	}
	// link tanpa ifIndex maupun nama
	p = roundTrip(t, gosnmp.Version2c, gosnmp.SnmpTrap{Variables: []gosnmp.SnmpPDU{{Name: ".1.3.6.1.6.3.1.1.4.1.0", Type: gosnmp.ObjectIdentifier, Value: ".1.3.6.1.6.3.1.1.5.3"}}})
	if d = Decode(p, nil); d.Summary != "Port (tidak disebut) putus (linkDown)" {
		t.Fatal(d.Summary)
	}
}
