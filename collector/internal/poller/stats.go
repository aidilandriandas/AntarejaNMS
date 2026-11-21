package poller

import (
	"math"
	"math/big"
	"sync"
	"time"
)

// Stats menyimpan pengamatan terakhir di memori untuk penilaian problem:
// jendela ping 5 putaran, jumlah kegagalan SNMP berturut-turut, dan counter port untuk menghitung laju.
// Setelah collector restart, nilainya kosong sampai terisi kembali — engine problem memperlakukannya sebagai "belum diketahui".
type Stats struct {
	mu        sync.Mutex
	ping      map[int64][]pingSample
	snmpFails map[int64]int
	snmpSeen  map[int64]bool
	counters  map[portKey]counterSample
	util      map[portKey]float64
}

type pingSample struct {
	rtt  float64 // NaN bila tidak ada balasan
	loss float64
}

type portKey struct {
	dev int64
	idx int
}

type counterSample struct {
	in, out *big.Int
	at      time.Time
}

const pingWindow = 5

func NewStats() *Stats {
	return &Stats{ping: map[int64][]pingSample{}, snmpFails: map[int64]int{}, snmpSeen: map[int64]bool{},
		counters: map[portKey]counterSample{}, util: map[portKey]float64{}}
}

func (s *Stats) AddPing(dev int64, ok bool, rtt, loss float64) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if !ok {
		rtt = math.NaN()
	}
	w := append(s.ping[dev], pingSample{rtt: rtt, loss: loss})
	if len(w) > pingWindow {
		w = w[len(w)-pingWindow:]
	}
	s.ping[dev] = w
}

// PingAvg: rata-rata RTT (hanya paket yang dibalas) dan loss pada jendela terakhir; n = jumlah putaran.
func (s *Stats) PingAvg(dev int64) (rtt, loss float64, n int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	w := s.ping[dev]
	var sum float64
	var cnt int
	for _, x := range w {
		loss += x.loss
		if !math.IsNaN(x.rtt) {
			sum += x.rtt
			cnt++
		}
	}
	if len(w) > 0 {
		loss /= float64(len(w))
	}
	rtt = math.NaN()
	if cnt > 0 {
		rtt = sum / float64(cnt)
	}
	return rtt, loss, len(w)
}

func (s *Stats) SNMPResult(dev int64, ok bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.snmpSeen[dev] = true
	if ok {
		s.snmpFails[dev] = 0
	} else {
		s.snmpFails[dev]++
	}
}

// SNMPFails: jumlah putaran SNMP gagal berturut-turut; known=false bila belum pernah diamati sejak start.
func (s *Stats) SNMPFails(dev int64) (n int, known bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.snmpFails[dev], s.snmpSeen[dev]
}

// Rate menghitung laju bps dari selisih counter. nil bila belum ada sampel sebelumnya,
// counter mundur (reset/reboot), atau selang terlalu pendek.
func (s *Stats) Rate(dev int64, idx int, in, out *big.Int, speed uint64, at time.Time) (inBps, outBps, util *float64) {
	if in == nil || out == nil {
		return nil, nil, nil
	}
	k := portKey{dev, idx}
	s.mu.Lock()
	defer s.mu.Unlock()
	prev, ok := s.counters[k]
	s.counters[k] = counterSample{in: new(big.Int).Set(in), out: new(big.Int).Set(out), at: at}
	if !ok {
		return nil, nil, nil
	}
	secs := at.Sub(prev.at).Seconds()
	if secs < 10 {
		return nil, nil, nil
	}
	di, do := new(big.Int).Sub(in, prev.in), new(big.Int).Sub(out, prev.out)
	if di.Sign() < 0 || do.Sign() < 0 {
		delete(s.util, k)
		return nil, nil, nil
	}
	fi, _ := new(big.Float).SetInt(di).Float64()
	fo, _ := new(big.Float).SetInt(do).Float64()
	a, b := fi*8/secs, fo*8/secs
	inBps, outBps = &a, &b
	if speed > 0 {
		u := math.Min(100, math.Max(a, b)/float64(speed)*100)
		util = &u
		s.util[k] = u
	}
	return inBps, outBps, util
}

// Util: pemakaian port terakhir yang dihitung; known=false bila belum ada.
func (s *Stats) Util(dev int64, idx int) (float64, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	u, ok := s.util[portKey{dev, idx}]
	return u, ok
}
