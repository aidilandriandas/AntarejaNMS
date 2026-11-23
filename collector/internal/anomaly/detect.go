// Package anomaly mencari nilai yang menyimpang jauh dari kebiasaan: rata-rata 15 menit terakhir dibandingkan
// dengan nilai pada jam yang sama selama 14 hari ke belakang. Statistiknya median dan MAD (median simpangan
// absolut), jadi satu-dua hari yang aneh di masa lalu tidak menggeser patokan.
package anomaly

import (
	"math"
	"sort"
)

const (
	MinHistory = 7   // butuh paling sedikit 7 hari data di jam yang sama
	ZOpen      = 4.0 // simpangan (dalam satuan sebaran) untuk dianggap menyimpang
	ZClose     = 2.5 // di bawah ini dianggap sudah normal lagi
	mbps       = 1e6
)

// Metric yang dinilai.
const (
	Latency = "latency"
	PortIn  = "port_in"
	PortOut = "port_out"
)

// Verdict hasil penilaian satu deret.
type Verdict struct {
	Anomalous bool    // menyimpang (untuk dibuka)
	Normal    bool    // sudah wajar (untuk ditutup); bukan kebalikan Anomalous karena ada histeresis
	Direction string  // up / down
	Baseline  float64 // median
	Spread    float64 // sebaran
	Score     float64 // (cur - median) / sebaran
}

// Stats: median dan sebaran (1,4826 x MAD) dari riwayat, NaN dibuang. ok=false bila riwayat kurang.
func Stats(hist []float64) (med, spread float64, ok bool) {
	v := make([]float64, 0, len(hist))
	for _, x := range hist {
		if !math.IsNaN(x) && !math.IsInf(x, 0) {
			v = append(v, x)
		}
	}
	if len(v) < MinHistory {
		return 0, 0, false
	}
	med = median(v)
	dev := make([]float64, len(v))
	for i, x := range v {
		dev[i] = math.Abs(x - med)
	}
	return med, 1.4826 * median(dev), true
}

func median(v []float64) float64 {
	s := append([]float64(nil), v...)
	sort.Float64s(s)
	n := len(s)
	if n%2 == 1 {
		return s[n/2]
	}
	return (s[n/2-1] + s[n/2]) / 2
}

// Judge menilai nilai sekarang terhadap riwayat untuk satu jenis metrik.
//
// Latensi hanya dinilai bila naik (turun itu kabar baik). Trafik dinilai dua arah: melonjak (banjir trafik,
// loop, unduhan besar) dan anjlok (kamera berhenti streaming, uplink pindah jalur). Selain simpangan statistik,
// perubahan harus besar secara nyata supaya deret yang sangat rata tidak memicu anomali dari perubahan kecil.
func Judge(metric string, cur float64, hist []float64) (Verdict, bool) {
	med, spread, ok := Stats(hist)
	if !ok || math.IsNaN(cur) {
		return Verdict{}, false
	}
	var floor float64
	if metric == Latency {
		floor = math.Max(2, 0.1*med)
	} else {
		floor = math.Max(1*mbps, 0.15*med)
	}
	if spread < floor {
		spread = floor
	}
	z := (cur - med) / spread
	v := Verdict{Baseline: med, Spread: spread, Score: z, Direction: "up"}
	if z < 0 {
		v.Direction = "down"
	}
	switch metric {
	case Latency:
		v.Anomalous = z >= ZOpen && cur >= 1.5*med && cur-med >= 5
		v.Normal = z < ZClose || cur < 1.3*med
	default:
		up := z >= ZOpen && cur >= 2*med && cur-med >= 5*mbps
		down := med >= 2*mbps && cur <= 0.25*med && -z >= ZOpen
		v.Anomalous = up || down
		if v.Direction == "up" {
			v.Normal = z < ZClose || cur < 1.6*med
		} else {
			v.Normal = -z < ZClose || cur > 0.5*med
		}
	}
	return v, true
}
