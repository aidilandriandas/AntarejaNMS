package cctv

import (
	"bytes"
	"image"
	"image/color"
	"image/jpeg"
	"math/rand"
	"testing"
)

func jpg(t *testing.T, fill func(x, y int) uint8) []byte {
	img := image.NewGray(image.Rect(0, 0, 640, 360))
	for y := 0; y < 360; y++ {
		for x := 0; x < 640; x++ {
			img.SetGray(x, y, color.Gray{fill(x, y)})
		}
	}
	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, img, &jpeg.Options{Quality: 80}); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func TestJudge(t *testing.T) {
	rng := rand.New(rand.NewSource(1))
	scene := func(x, y int) uint8 { return uint8((x/40+y/40)%2*120 + 60 + rng.Intn(10)) } // papan catur + noise
	cases := map[string]struct {
		fill func(x, y int) uint8
		want string
	}{
		"normal":   {scene, "ok"},
		"gelap":    {func(x, y int) uint8 { return uint8(rng.Intn(6)) }, "dark"},
		"tertutup": {func(x, y int) uint8 { return 128 + uint8(rng.Intn(3)) }, "covered"},
	}
	for name, c := range cases {
		r, err := Analyze(jpg(t, c.fill))
		if err != nil {
			t.Fatal(err)
		}
		if h, _ := Judge(r, 0); h != c.want {
			t.Errorf("%s: %s (kecerahan %.1f detail %.1f)", name, h, r.Brightness, r.Detail)
		}
	}
}

func TestFrozen(t *testing.T) {
	scene := func(sec int) func(x, y int) uint8 {
		return func(x, y int) uint8 {
			// pemandangan diam + "jam OSD" kecil di pojok yang berubah tiap detik
			if x < 24 && y < 12 && (x/4+sec)%2 == 0 {
				return 250
			}
			return uint8((x/40+y/40)%2*120 + 60)
		}
	}
	a, _ := Analyze(jpg(t, scene(1)))
	b, _ := Analyze(jpg(t, scene(1)))
	if a.Hash != b.Hash {
		t.Fatal("gambar identik harus sidik sama")
	}
	live, _ := Analyze(jpg(t, scene(2)))
	if a.Hash == live.Hash {
		t.Fatal("perubahan kecil (jam OSD) harus membuat sidik berbeda — pemandangan diam bukan beku")
	}
	if h, _ := Judge(a, FrozenRepeats); h != "frozen" {
		t.Fatal("3x identik = beku")
	}
}
