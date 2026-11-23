package poller

import (
	"math"
	"math/big"
	"testing"
	"time"
)

func TestPingWindow(t *testing.T) {
	s := NewStats()
	for i := 0; i < 7; i++ {
		s.AddPing(1, true, float64(i), 0)
	}
	s.AddPing(1, false, 0, 100)
	rtt, loss, n := s.PingAvg(1)
	if n != 5 || math.Abs(rtt-4.5) > 1e-9 || loss != 20 { // jendela: 3,4,5,6,(gagal)
		t.Fatalf("rtt=%v loss=%v n=%d", rtt, loss, n)
	}
	if _, _, n := s.PingAvg(99); n != 0 {
		t.Fatal("perangkat tanpa data harus n=0")
	}
}

func TestRate(t *testing.T) {
	s := NewStats()
	t0 := time.Unix(1000, 0)
	if a, _, _ := s.Rate(1, 2, big.NewInt(0), big.NewInt(0), 1_000_000, t0); a != nil {
		t.Fatal("sampel pertama harus nil")
	}
	in, out, u := s.Rate(1, 2, big.NewInt(3_750_000), big.NewInt(1_250_000), 1_000_000, t0.Add(60*time.Second))
	if *in != 500_000 || *out != 166_666.66666666666 || *u != 50 {
		t.Fatalf("in=%v out=%v util=%v", *in, *out, *u)
	}
	if a, _, _ := s.Rate(1, 2, big.NewInt(10), big.NewInt(10), 1_000_000, t0.Add(120*time.Second)); a != nil {
		t.Fatal("counter mundur (reboot) harus diabaikan")
	}
	if _, ok := s.Util(1, 2); ok {
		t.Fatal("util harus dihapus setelah counter reset")
	}
}

func TestSNMPFails(t *testing.T) {
	s := NewStats()
	if _, known := s.SNMPFails(1); known {
		t.Fatal("belum diamati")
	}
	s.SNMPResult(1, false)
	s.SNMPResult(1, false)
	if n, _ := s.SNMPFails(1); n != 2 {
		t.Fatal(n)
	}
	s.SNMPResult(1, true)
	if n, _ := s.SNMPFails(1); n != 0 {
		t.Fatal(n)
	}
}

func TestErrRate(t *testing.T) {
	s := NewStats()
	t0 := time.Now()
	b := func(v int64) *big.Int { return big.NewInt(v) }
	if e, d := s.ErrRate(1, 2, b(100), b(0), b(5), nil, t0); e != nil || d != nil {
		t.Fatal("sampel pertama harus nil")
	}
	e, d := s.ErrRate(1, 2, b(160), b(0), b(25), nil, t0.Add(2*time.Minute))
	if e == nil || *e != 30 || d == nil || *d != 10 {
		t.Fatalf("e=%v d=%v", e, d)
	}
	// counter reset (reboot): nil, bukan angka negatif
	if e, _ := s.ErrRate(1, 2, b(3), b(0), b(0), nil, t0.Add(4*time.Minute)); e != nil {
		t.Fatal("counter mundur harus nil")
	}
	// perangkat tidak melaporkan counter
	s.ErrRate(1, 3, nil, nil, nil, nil, t0)
	if e, d := s.ErrRate(1, 3, nil, nil, nil, nil, t0.Add(time.Minute)); e != nil || d != nil {
		t.Fatal("tanpa counter harus nil")
	}
}
