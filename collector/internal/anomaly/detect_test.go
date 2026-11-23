package anomaly

import (
	"encoding/json"
	"math"
	"strings"
	"testing"
)

func rep(v float64, n int) []float64 {
	out := make([]float64, n)
	for i := range out {
		out[i] = v
	}
	return out
}

func TestStats(t *testing.T) {
	if _, _, ok := Stats([]float64{1, 2, 3, math.NaN(), 4, 5, 6}); ok {
		t.Fatal("6 nilai valid seharusnya kurang")
	}
	med, sp, ok := Stats([]float64{10, 11, 9, 10, 12, 10, 8, 500})
	if !ok || med != 10 || sp > 2 {
		t.Fatalf("med=%v spread=%v: pencilan 500 tidak boleh menggeser patokan", med, sp)
	}
}

func TestLatency(t *testing.T) {
	h := []float64{20, 22, 21, 19, 23, 20, 21, 22, 20, 21, 19, 20, 22, 21}
	if v, _ := Judge(Latency, 24, h); v.Anomalous {
		t.Fatalf("24 ms masih wajar: %+v", v)
	}
	v, ok := Judge(Latency, 80, h)
	if !ok || !v.Anomalous || v.Direction != "up" || v.Baseline != 21 {
		t.Fatalf("80 ms seharusnya anomali: %+v", v)
	}
	// turun tidak pernah anomali untuk latensi
	if v, _ := Judge(Latency, 2, h); v.Anomalous {
		t.Fatal("latensi turun dianggap anomali")
	}
	// histeresis: 40 ms belum dianggap normal, 25 ms sudah
	if v, _ := Judge(Latency, 40, h); v.Normal {
		t.Fatalf("40 ms seharusnya belum normal: %+v", v)
	}
	if v, _ := Judge(Latency, 25, h); !v.Normal {
		t.Fatal("25 ms seharusnya normal")
	}
	// latensi sangat rendah: kenaikan 1 ke 4 ms tidak berarti
	if v, _ := Judge(Latency, 4, rep(1, 14)); v.Anomalous {
		t.Fatal("1 ke 4 ms dianggap anomali")
	}
}

func TestTraffic(t *testing.T) {
	h := []float64{40e6, 42e6, 38e6, 41e6, 39e6, 40e6, 43e6, 40e6, 37e6, 41e6}
	if v, _ := Judge(PortIn, 0.3e6, h); !v.Anomalous || v.Direction != "down" {
		t.Fatalf("anjlok ke 0,3 Mbps seharusnya anomali: %+v", v)
	}
	if v, _ := Judge(PortIn, 150e6, h); !v.Anomalous || v.Direction != "up" {
		t.Fatalf("melonjak ke 150 Mbps seharusnya anomali: %+v", v)
	}
	if v, _ := Judge(PortIn, 55e6, h); v.Anomalous {
		t.Fatalf("55 Mbps masih wajar: %+v", v)
	}
	// port hampir kosong: 0,2 ke 3 Mbps tidak dihitung (selisih kecil)
	if v, _ := Judge(PortOut, 3e6, rep(0.2e6, 14)); v.Anomalous {
		t.Fatal("selisih 2,8 Mbps dianggap anomali")
	}
	// port kecil turun ke nol: median di bawah 2 Mbps tidak dinilai turun
	if v, _ := Judge(PortOut, 0, rep(1.5e6, 14)); v.Anomalous {
		t.Fatal("port 1,5 Mbps ke 0 dianggap anomali")
	}
	if _, ok := Judge(PortIn, 1, rep(1, 5)); ok {
		t.Fatal("riwayat 5 hari seharusnya belum dinilai")
	}
}

func TestParseDescribe(t *testing.T) {
	js := `{"status":"success","data":{"resultType":"vector","result":[
		{"metric":{"device_id":"7","if_index":"3","if_name":"ether3"},"value":[1700000000,"41000000"]},
		{"metric":{"device_id":"8"},"value":[1700000000,"12.5"]},
		{"metric":{"device_id":"x"},"value":[1700000000,"1"]},
		{"metric":{"device_id":"9"},"value":[1700000000,"NaN"]}]}}`
	got, err := parse(json.NewDecoder(strings.NewReader(js)))
	if err != nil || len(got) != 2 {
		t.Fatalf("got=%v err=%v", got, err)
	}
	if got[series{dev: 7, idx: 3, name: "ether3"}].val != 41e6 || got[series{dev: 8, idx: -1}].val != 12.5 {
		t.Fatalf("%v", got)
	}
	if s := Describe(PortIn, "ether3", "down", 0.3e6, 40e6); s != "Trafik masuk ether3 anjlok: 300 kbps, biasanya 40 Mbps di jam ini" {
		t.Fatal(s)
	}
	if s := Describe(Latency, "", "up", 80.44, 21); s != "Latensi naik tidak biasa: 80,4 ms, biasanya 21 ms di jam ini" {
		t.Fatal(s)
	}
}
