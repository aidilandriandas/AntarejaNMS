package store

import "testing"

func TestParseLimits(t *testing.T) {
	l := ParseLimits([]byte(`{"latency_ms": 250, "loss_pct": "x", "util_pct": 95, "signal": [-75, -82], "temperature": [65, 80], "cpu": [90, 90], "aneh": 1}`))
	if l.LatencyMS != 250 || l.LossPct != 0 || l.UtilPct != 95 {
		t.Fatalf("%+v", l)
	}
	if l.Classes["signal"] != [2]float64{-75, -82} || l.Classes["temperature"] != [2]float64{65, 80} {
		t.Fatalf("%+v", l.Classes)
	}
	if _, ok := l.Classes["cpu"]; ok {
		t.Fatal("peringatan = kritis harus diabaikan")
	}
	if e := ParseLimits(nil); e.LatencyMS != 0 || len(e.Classes) != 0 {
		t.Fatal("kosong")
	}
}
