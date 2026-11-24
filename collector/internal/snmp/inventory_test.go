package snmp

import (
	"math"
	"testing"
	"time"
)

func TestParseDateAndTime(t *testing.T) {
	// 2026-11-24 13:30:15.5 +07:00
	b := []byte{0x07, 0xEA, 11, 24, 13, 30, 15, 5, '+', 7, 0}
	dt, tz, err := ParseDateAndTime(b)
	if err != nil || !tz {
		t.Fatal(err, tz)
	}
	want := time.Date(2026, 11, 24, 6, 30, 15, 5e8, time.UTC)
	if !dt.Equal(want) {
		t.Fatalf("%v != %v", dt, want)
	}
	if _, _, err := ParseDateAndTime(b[:7]); err == nil {
		t.Fatal("7 oktet harus ditolak")
	}
	if _, tz, err := ParseDateAndTime(b[:8]); err != nil || tz {
		t.Fatal("8 oktet tanpa zona", err)
	}
}

func TestSkew(t *testing.T) {
	ref := time.Date(2026, 11, 24, 6, 0, 0, 0, time.UTC)
	cases := []struct {
		dev  time.Time
		tz   bool
		want float64
	}{
		{ref.Add(90 * time.Second), true, 90},
		{ref.Add(-8 * time.Hour), true, -8 * 3600},           // zona diketahui: selisih asli
		{ref.Add(7*time.Hour + 42*time.Second), false, 42},   // jam lokal WIB tanpa zona
		{ref.Add(8*time.Hour - 3*time.Minute), false, -180},  // WITA, perangkat 3 menit lambat
		{ref.Add(-(time.Hour + 20*time.Second)), false, -20}, // zona negatif
	}
	for i, c := range cases {
		if got := Skew(c.dev, c.tz, ref); math.Abs(got-c.want) > 0.001 {
			t.Errorf("kasus %d: %v, harus %v", i, got, c.want)
		}
	}
}

func TestVersionFromDescr(t *testing.T) {
	for d, want := range map[string]string{
		"Cisco IOS Software, C2960X Software (C2960X-UNIVERSALK9-M), Version 15.2(4)E7, RELEASE SOFTWARE (fc2)": "15.2(4)E7",
		"Hikvision version: V5.6.3 build 190923": "5.6.3",
		"RouterOS RB4011iGS+":                    "",
	} {
		if got := VersionFromDescr(d); got != want {
			t.Errorf("%q: %q, harus %q", d, got, want)
		}
	}
}
