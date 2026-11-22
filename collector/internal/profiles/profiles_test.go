package profiles

import (
	"strings"
	"testing"
)

// fakeQ meniru perangkat: oid lengkap → nilai.
type fakeQ map[string]any

func (f fakeQ) Get(oids []string) (map[string]any, error) {
	out := map[string]any{}
	for _, o := range oids {
		if v, ok := f[o]; ok {
			out[o] = v
		}
	}
	return out, nil
}

func (f fakeQ) Walk(base string) (map[string]any, error) {
	out := map[string]any{}
	for o, v := range f {
		if strings.HasPrefix(o, base+".") {
			out[strings.TrimPrefix(o, base+".")] = v
		}
	}
	return out, nil
}

func find(rs []Reading, key string) *Reading {
	for i := range rs {
		if rs[i].Key == key {
			return &rs[i]
		}
	}
	return nil
}

func TestDetect(t *testing.T) {
	q := fakeQ{mtxrHlVoltage: 240.0, upsBatteryStatus: 2.0}
	ids := Detect(q, ".1.3.6.1.4.1.8072.3.2.10")
	got := strings.Join(ids, ",")
	if got != "host-resources,entity-sensor,mikrotik,ups-mib" {
		t.Fatal(got)
	}
	if ids := Detect(fakeQ{}, ".1.3.6.1.4.1.9.1.1208"); !strings.Contains(strings.Join(ids, ","), "cisco") {
		t.Fatal("cisco lewat sysObjectID", ids)
	}
}

func TestHostResourcesAndJudge(t *testing.T) {
	q := fakeQ{
		hrProcessorLoad + ".196608": 90.0, hrProcessorLoad + ".196609": 100.0,
		hrStorageType + ".1": hrStorageRam, hrStorageSize + ".1": 1000.0, hrStorageUsed + ".1": 900.0,
		hrStorageType + ".31": hrStorageDisk, hrStorageDescr + ".31": "/", hrStorageSize + ".31": 100.0, hrStorageUsed + ".31": 50.0,
		hrStorageType + ".32": hrStorageDisk, hrStorageDescr + ".32": "/run", hrStorageSize + ".32": 10.0, hrStorageUsed + ".32": 10.0,
	}
	rs := Collect(q, []string{"host-resources"})
	if c := find(rs, "cpu"); c == nil || c.Value != 95 || c.Status != "crit" {
		t.Fatalf("cpu %+v", c)
	}
	if m := find(rs, "memory"); m == nil || m.Value != 90 || m.Status != "warn" {
		t.Fatalf("mem %+v", m)
	}
	if d := find(rs, "disk:"); d == nil || d.Status != "ok" {
		t.Fatalf("disk / %+v", rs)
	}
	if find(rs, "disk:run") != nil {
		t.Fatal("/run harus dilewati")
	}
	// UCD diutamakan untuk memori Linux
	q[ucdMemTotal], q[ucdMemAvail], q[ucdMemBuffer], q[ucdMemCached] = 1000.0, 300.0, 100.0, 200.0
	if m := find(Collect(q, []string{"host-resources"}), "memory"); m.Value != 40 {
		t.Fatalf("memori UCD %+v", m)
	}
}

func TestMikrotikWireless(t *testing.T) {
	q := fakeQ{
		mtxrHlVoltage: 241.0, mtxrHlTemp: 385.0,
		mtxrWlStatSignal + ".5": -79.0, mtxrWlStatSsid + ".5": "PTP-PIT-A", mtxrWlStatTxRate + ".5": 144400000.0, mtxrWlStatFreq + ".5": 5180.0,
		mtxrGaugeName + ".1": "cpu-temperature", mtxrGaugeValue + ".1": 52.0, mtxrGaugeUnit + ".1": 1.0,
	}
	rs := Collect(q, []string{"mikrotik"})
	if v := find(rs, "volt:input"); v == nil || v.Value != 24.1 {
		t.Fatalf("tegangan %+v", v)
	}
	if s := find(rs, "wl:signal:5"); s == nil || s.Status != "crit" || !strings.Contains(s.Label, "PTP-PIT-A") {
		t.Fatalf("sinyal %+v", s)
	}
	if r := find(rs, "wl:tx:5"); r == nil || r.Value != 144.4 {
		t.Fatalf("tx rate %+v", r)
	}
	if g := find(rs, "temperature:cpu-temperature"); g == nil || g.Value != 52 {
		t.Fatalf("gauge v7 %+v", rs)
	}
}

func TestUbiquitiAndUPS(t *testing.T) {
	q := fakeQ{ubntWlSignal + ".1": -62.0, ubntWlCCQ + ".1": 45.0, ubntWlTxRate + ".1": 300000.0, ubntAMQuality + ".1": 88.0, ubntRadioDist + ".1": 4200.0}
	rs := Collect(q, []string{"ubiquiti-airmax"})
	if s := find(rs, "wl:signal:1"); s.Status != "ok" {
		t.Fatal("sinyal -62 ok")
	}
	if c := find(rs, "wl:ccq:1"); c.Status != "crit" {
		t.Fatal("ccq 45 crit")
	}
	if r := find(rs, "wl:tx:1"); r.Value != 300 {
		t.Fatalf("rate %v", r.Value)
	}
	if d := find(rs, "wl:distance:1"); d.Value != 4.2 {
		t.Fatalf("jarak %v", d.Value)
	}

	u := fakeQ{upsOutputSource: 5.0, upsSecondsOnBatt: 600.0, upsBatteryStatus: 2.0, upsChargeRemain: 64.0, upsMinutesRemain: 4.0, upsOutputLoad + ".1": 41.0}
	ur := Collect(u, []string{"ups-mib"})
	if s := find(ur, "ups:source"); s.Status != "crit" || s.Text != "BATERAI sejak 10 menit" {
		t.Fatalf("sumber %+v", s)
	}
	if r := find(ur, "ups:runtime"); r.Status != "crit" {
		t.Fatal("cadangan 4 menit = crit")
	}
	if c := find(ur, "ups:charge"); c.Status != "ok" {
		t.Fatal("muatan 64% ok")
	}
	a := Collect(fakeQ{apcRunTime: 180000.0, apcOutputStatus: 2.0}, []string{"apc"})
	if r := find(a, "ups:runtime"); r.Value != 30 {
		t.Fatalf("APC runtime timeticks→menit %v", r.Value)
	}
}

func TestEntitySensor(t *testing.T) {
	q := fakeQ{entSensorType + ".11": 8.0, entSensorScale + ".11": 9.0, entSensorPrec + ".11": 1.0, entSensorValue + ".11": 456.0, entSensorOper + ".11": 1.0, entPhysName + ".11": "Inlet",
		entSensorType + ".12": 4.0, entSensorScale + ".12": 8.0, entSensorPrec + ".12": 0.0, entSensorValue + ".12": 12050.0, entSensorOper + ".12": 1.0,
		entSensorType + ".13": 8.0, entSensorValue + ".13": 99.0, entSensorOper + ".13": 2.0}
	rs := Collect(q, []string{"entity-sensor"})
	if r := find(rs, "temperature:ent11"); r == nil || r.Value != 45.6 || r.Label != "Inlet" {
		t.Fatalf("%+v", rs)
	}
	if r := find(rs, "voltage:ent12"); r == nil || r.Value != 12.05 {
		t.Fatalf("milivolt %+v", r)
	}
	if find(rs, "temperature:ent13") != nil {
		t.Fatal("sensor tidak tersedia harus dilewati")
	}
}

func TestJudgeOverride(t *testing.T) {
	r := Reading{Class: "signal", Value: -76}
	Judge(&r)
	if r.Status != "warn" {
		t.Fatal("bawaan: -76 dBm = peringatan")
	}
	r = Reading{Class: "signal", Value: -76}
	JudgeWith(&r, map[string][2]float64{"signal": {-80, -86}}) // radio PTP jarak jauh
	if r.Status != "ok" {
		t.Fatal("ambang khusus -80/-86: -76 dBm = normal")
	}
	r = Reading{Class: "voltage", Value: 11.2}
	JudgeWith(&r, map[string][2]float64{"voltage": {11.5, 10.8}}) // kelas tanpa ambang bawaan, arah rendah
	if r.Status != "warn" {
		t.Fatalf("tegangan 11.2 V dengan ambang 11.5/10.8 = peringatan, dapat %s", r.Status)
	}
}
