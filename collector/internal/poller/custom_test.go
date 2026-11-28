package poller

import (
	"strings"
	"testing"

	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

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

func TestCustomSensors(t *testing.T) {
	// OID merek lain (contoh fiktif): satu nilai sinyal x0.1, dan kolom tabel suhu per radio
	q := fakeQ{".1.3.6.1.4.1.99999.1.2.0": -655.0, ".1.3.6.1.4.1.99999.3.1": 41.0, ".1.3.6.1.4.1.99999.3.2": "77 C"}
	got := CustomSensors(q, []store.CustomOID{
		{ID: 1, Label: "Sinyal radio", OID: "1.3.6.1.4.1.99999.1.2.0", Mode: "get", Class: "signal", Unit: "dBm", Scale: 0.1, Group: "radio"},
		{ID: 2, Label: "Suhu radio", OID: ".1.3.6.1.4.1.99999.3", Mode: "walk", Class: "temperature", Unit: "°C", Scale: 1},
		{ID: 3, Label: "Tidak ada", OID: ".1.3.6.1.4.1.99999.9.0", Mode: "get", Class: "other"},
	}, map[string][2]float64{"signal": {-60, -70}})
	if len(got) != 3 {
		t.Fatalf("3 sensor (1 get + 2 baris walk), dapat %d: %+v", len(got), got)
	}
	if got[0].Key != "ox:1" || *got[0].Value != -65.5 || got[0].Status != "warn" {
		t.Fatalf("sinyal x0,1 dengan ambang khusus -60/-70: %+v", got[0])
	}
	if got[1].Key != "ox:2:1" || got[1].Label != "Suhu radio 1" || got[2].Group != "sistem" || *got[2].Value != 77 || got[2].Status != "crit" {
		t.Fatalf("baris tabel: %+v %+v", got[1], got[2])
	}
}
