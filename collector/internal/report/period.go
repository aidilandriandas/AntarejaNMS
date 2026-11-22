// Package report menyusun ringkasan operasional (ketersediaan, gangguan, perubahan) untuk satu periode
// dan mengirimkannya lewat kanal notifikasi sesuai jadwal. Hanya membaca database ANTAREJA.
package report

import (
	"fmt"
	"time"
)

// Period adalah rentang laporan [Start, End) dalam zona waktu server.
type Period struct {
	Start, End time.Time
	Key        string // penanda unik periode (untuk mencegah kirim ganda)
	Label      string // untuk tampilan, mis. "Sabtu, 21/11/2026" / "16–22/11/2026" / "Oktober 2026"
}

var bulan = []string{"", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"}
var hari = []string{"Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"}

func day(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, t.Location())
}

// LastFull mengembalikan periode penuh terakhir sebelum `now` (kemarin / minggu lalu Senin–Minggu / bulan lalu),
// beserta saat kirim yang dijadwalkan untuk periode itu (awal periode berikutnya + jam kirim).
func LastFull(freq string, now time.Time, hour int) (Period, time.Time, error) {
	today := day(now)
	var p Period
	switch freq {
	case "daily":
		p.Start, p.End = today.AddDate(0, 0, -1), today
		p.Key = p.Start.Format("2006-01-02")
		p.Label = fmt.Sprintf("%s, %s", hari[p.Start.Weekday()], p.Start.Format("02/01/2006"))
	case "weekly":
		wd := (int(today.Weekday()) + 6) % 7 // Senin = 0
		monday := today.AddDate(0, 0, -wd)
		p.Start, p.End = monday.AddDate(0, 0, -7), monday
		p.Key = p.Start.Format("2006-01-02")
		last := p.End.AddDate(0, 0, -1)
		p.Label = fmt.Sprintf("%s sampai %s", p.Start.Format("02/01"), last.Format("02/01/2006"))
	case "monthly":
		first := time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, today.Location())
		p.Start, p.End = first.AddDate(0, -1, 0), first
		p.Key = p.Start.Format("2006-01")
		p.Label = fmt.Sprintf("%s %d", bulan[p.Start.Month()], p.Start.Year())
	default:
		return p, time.Time{}, fmt.Errorf("frekuensi tidak dikenal: %s", freq)
	}
	return p, p.End.Add(time.Duration(hour) * time.Hour), nil
}
