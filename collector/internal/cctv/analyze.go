// Package cctv memeriksa kesehatan gambar kamera: gelap, tertutup (gambar polos), beku, atau tanpa sinyal.
// Gambar diambil dari go2rtc (internal) memakai sub-stream; hanya dibaca, tidak disimpan.
package cctv

import (
	"bytes"
	"image"
	_ "image/jpeg" // dekoder JPEG
	"math"
)

// Thumb adalah gambar abu-abu kecil (64×36) untuk perbandingan antarpengambilan.
type Thumb [64 * 36]uint8

type Result struct {
	Brightness float64 // rata-rata 0–255
	Detail     float64 // simpangan baku kecerahan
	Thumb      Thumb
	Hash       uint64 // sidik seluruh piksel: gambar beku = identik persis (jam OSD kamera hidup selalu berubah)
}

// Analyze mengurai JPEG lalu menghitung kecerahan, detail, dan thumbnail.
func Analyze(jpg []byte) (Result, error) {
	img, _, err := image.Decode(bytes.NewReader(jpg))
	if err != nil {
		return Result{}, err
	}
	b := img.Bounds()
	var r Result
	var sum, sq float64
	for y := 0; y < 36; y++ {
		for x := 0; x < 64; x++ {
			// rata-rata blok kecil di sekitar titik sampel (lebih tahan noise daripada satu piksel)
			cx := b.Min.X + (2*x+1)*b.Dx()/128
			cy := b.Min.Y + (2*y+1)*b.Dy()/72
			var acc float64
			n := 0
			for dy := -1; dy <= 1; dy++ {
				for dx := -1; dx <= 1; dx++ {
					px, py := cx+dx, cy+dy
					if px < b.Min.X || py < b.Min.Y || px >= b.Max.X || py >= b.Max.Y {
						continue
					}
					cr, cg, cb, _ := img.At(px, py).RGBA()
					acc += (0.299*float64(cr) + 0.587*float64(cg) + 0.114*float64(cb)) / 257
					n++
				}
			}
			v := acc / float64(max(1, n))
			r.Thumb[y*64+x] = uint8(math.Round(v))
			sum += v
			sq += v * v
		}
	}
	// Sidik FNV-1a atas kecerahan SEMUA piksel (bukan sampel) agar perubahan kecil seperti detik di OSD tertangkap
	h := uint64(14695981039346656037)
	for y := b.Min.Y; y < b.Max.Y; y++ {
		for x := b.Min.X; x < b.Max.X; x++ {
			cr, cg, cb, _ := img.At(x, y).RGBA()
			h ^= uint64((cr*299 + cg*587 + cb*114) / 1000 >> 8)
			h *= 1099511628211
		}
	}
	r.Hash = h
	n := float64(len(r.Thumb))
	r.Brightness = sum / n
	r.Detail = math.Sqrt(math.Max(0, sq/n-r.Brightness*r.Brightness))
	return r, nil
}

// Diff adalah rata-rata selisih absolut dua thumbnail (0 = identik).
func Diff(a, b Thumb) float64 {
	var s float64
	for i := range a {
		s += math.Abs(float64(a[i]) - float64(b[i]))
	}
	return s / float64(len(a))
}

// Ambang penilaian (dipilih konservatif agar kamera malam dengan IR tidak dianggap gelap).
const (
	DarkBelow     = 12.0 // kecerahan rata-rata
	CoveredBelow  = 6.0  // simpangan baku: gambar hampir polos (tertutup lumpur/debu/kain, lensa buram)
	FrozenRepeats = 3    // berapa pengambilan berturut-turut yang identik persis sebelum dianggap beku
)

// Judge menilai satu hasil. sameCount = jumlah pengambilan berturut-turut yang identik dengan sebelumnya.
func Judge(r Result, sameCount int) (health, detail string) {
	switch {
	case r.Brightness < DarkBelow:
		return "dark", "gambar gelap"
	case r.Detail < CoveredBelow:
		return "covered", "gambar polos, kemungkinan lensa tertutup / buram"
	case sameCount >= FrozenRepeats:
		return "frozen", "gambar tidak berubah (beku)"
	}
	return "ok", ""
}
