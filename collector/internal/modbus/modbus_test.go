package modbus

import (
	"context"
	"math"
	"strings"
	"testing"
	"time"
)

func f(v float64) *float64 { return &v }

func TestDecode(t *testing.T) {
	bits := math.Float32bits(13.25)
	cases := []struct {
		regs  []uint16
		dtype string
		swap  bool
		want  float64
	}{
		{[]uint16{1234}, "u16", false, 1234},
		{[]uint16{0xFFFE}, "s16", false, -2},
		{[]uint16{0x0001, 0x0002}, "u32", false, 65538},
		{[]uint16{0x0002, 0x0001}, "u32", true, 65538},
		{[]uint16{0xFFFF, 0xFFFF}, "s32", false, -1},
		{[]uint16{uint16(bits >> 16), uint16(bits)}, "f32", false, 13.25},
		{[]uint16{uint16(bits), uint16(bits >> 16)}, "f32", true, 13.25},
	}
	for _, c := range cases {
		if got, err := Decode(c.regs, c.dtype, c.swap); err != nil || got != c.want {
			t.Errorf("%s %v swap=%v: %v %v", c.dtype, c.regs, c.swap, got, err)
		}
	}
	if _, err := Decode([]uint16{1}, "u32", false); err == nil {
		t.Error("register kurang harus galat")
	}
}

func TestJudge(t *testing.T) {
	p := Point{WarnLow: f(12.2), CritLow: f(11.8), WarnHigh: f(14.6), CritHigh: f(15)}
	for v, want := range map[float64]string{13: "ok", 12.2: "warn", 11.5: "crit", 14.8: "warn", 15.2: "crit"} {
		if got := Judge(p, v); got != want {
			t.Errorf("%v: %s, mau %s", v, got, want)
		}
	}
	if Judge(Point{}, 999) != "ok" {
		t.Error("tanpa ambang selalu ok")
	}
}

func TestReadAll(t *testing.T) {
	srv := newFake(t)
	bits := math.Float32bits(27.5)
	srv.input[0x3104] = 1325                                          // tegangan baterai x100
	srv.holding[10], srv.holding[11] = uint16(bits>>16), uint16(bits) // suhu f32
	srv.holding[20] = 0xFFF6                                          // arus -10 (s16, x0.1)
	points := []Point{
		{ID: 1, Label: "Baterai", Fn: ReadInput, Address: 0x3104, DType: "u16", Scale: 0.01, WarnLow: f(12.2), CritLow: f(11.8)},
		{ID: 2, Label: "Suhu panel", Fn: ReadHolding, Address: 10, DType: "f32", Scale: 1, WarnHigh: f(25)},
		{ID: 3, Label: "Arus", Fn: ReadHolding, Address: 20, DType: "s16", Scale: 0.1},
		{ID: 4, Label: "Tidak ada", Fn: ReadHolding, Address: 999, DType: "u16", Scale: 1},
	}
	res, err := ReadAll(context.Background(), "127.0.0.1", srv.port, 1, points, 2*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	if res[0].Value == nil || math.Abs(*res[0].Value-13.25) > 1e-9 || res[0].Status != "ok" {
		t.Errorf("baterai: %+v", res[0])
	}
	if res[1].Value == nil || *res[1].Value != 27.5 || res[1].Status != "warn" {
		t.Errorf("suhu: %+v", res[1])
	}
	if res[2].Value == nil || *res[2].Value != -1 {
		t.Errorf("arus: %+v", res[2])
	}
	if res[3].Value != nil || !strings.Contains(res[3].Error, "alamat register tidak ada") {
		t.Errorf("alamat tidak ada: %+v", res[3])
	}
	for _, fn := range srv.fns {
		if fn != ReadHolding && fn != ReadInput {
			t.Fatalf("hanya fungsi baca yang boleh dikirim, terkirim fungsi %d", fn)
		}
	}
	c, _ := Dial(context.Background(), "127.0.0.1", srv.port, 1, time.Second)
	defer c.Close()
	if _, err := c.Read(6, 0, 1); err == nil {
		t.Fatal("fungsi 06 (tulis) harus ditolak sebelum dikirim")
	}
	if _, err := Dial(context.Background(), "127.0.0.1", 1, 1, time.Second); err == nil {
		t.Fatal("port tertutup harus galat")
	}
}
