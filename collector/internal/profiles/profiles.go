// Package profiles membaca sensor & kesehatan perangkat (CPU, memori, suhu, sinyal radio, UPS, dll.)
// berdasarkan profil per vendor/MIB. Setiap profil ditulis dari dokumen MIB publik yang disebut di berkasnya.
//
// Prinsip:
//   - Hanya GET/WALK (baca). OID yang tidak ada di perangkat dilewati tanpa galat.
//   - Deteksi profil: awalan sysObjectID, atau "probe" (OID khas yang dijawab perangkat).
//   - Profil vendor WAJIB diverifikasi dengan snmpwalk perangkat asli sebelum dianggap final;
//     bila nilai/skala berbeda pada firmware tertentu, perbaiki di sini dan catat sumbernya.
package profiles

import (
	"fmt"
	"math"
	"sort"
	"strings"
)

// Q adalah akses SNMP yang dibutuhkan profil (dipenuhi oleh snmp.Session; mudah dipalsukan untuk uji).
type Q interface {
	Get(oids []string) (map[string]any, error)
	Walk(base string) (map[string]any, error)
}

// Reading adalah satu nilai sensor.
type Reading struct {
	Key    string  // unik per perangkat, mis. "cpu", "temp:board", "wl:signal:3"
	Class  string  // cpu, memory, disk, temperature, voltage, fan, signal, noise, ccq, rate, quality, capacity, clients, frequency, distance, battery, runtime, load, ups_status, power
	Label  string  // nama untuk tampilan (Indonesia)
	Unit   string  // %, °C, V, dBm, Mbps, MHz, km, menit, …
	Value  float64 // NaN bila hanya teks
	Text   string  // nilai teks (status enum), opsional
	Status string  // ok | warn | crit (diisi Judge bila kosong)
	Group  string  // pengelompokan tampilan: sistem, radio, daya, lingkungan
}

type Profile struct {
	ID        string
	Name      string
	OIDPrefix []string // cocok bila sysObjectID diawali salah satu ini
	Probe     string   // OID yang bila dijawab menandakan profil cocok (deteksi tanpa sysObjectID)
	Generic   bool     // dicoba pada semua perangkat (MIB standar)
	Collect   func(q Q) []Reading
}

var registry []Profile

func register(p Profile) { registry = append(registry, p) }

// All mengembalikan semua profil (untuk dokumentasi/uji).
func All() []Profile { return registry }

// Detect memilih profil yang berlaku untuk perangkat. Probe dikirim dalam satu permintaan GET.
func Detect(q Q, sysObjectID string) []string {
	var ids, probes []string
	byProbe := map[string]string{}
	for _, p := range registry {
		switch {
		case p.Generic:
			ids = append(ids, p.ID)
		case matchPrefix(sysObjectID, p.OIDPrefix):
			ids = append(ids, p.ID)
		case p.Probe != "":
			probes = append(probes, p.Probe)
			byProbe[p.Probe] = p.ID
		}
	}
	if len(probes) > 0 {
		if res, err := q.Get(probes); err == nil {
			for oid := range res {
				if id, ok := byProbe[oid]; ok {
					ids = append(ids, id)
				}
			}
		}
	}
	sort.SliceStable(ids, func(i, j int) bool { return order(ids[i]) < order(ids[j]) })
	return ids
}

func order(id string) int {
	for i, p := range registry {
		if p.ID == id {
			return i
		}
	}
	return 999
}

func matchPrefix(oid string, prefixes []string) bool {
	for _, p := range prefixes {
		if oid == p || strings.HasPrefix(oid, p+".") {
			return true
		}
	}
	return false
}

// Collect menjalankan profil terpilih dan menilai statusnya. Kunci ganda: pembacaan pertama yang dipakai.
func Collect(q Q, ids []string) []Reading {
	var out []Reading
	seen := map[string]bool{}
	for _, id := range ids {
		for _, p := range registry {
			if p.ID != id {
				continue
			}
			for _, r := range p.Collect(q) {
				if seen[r.Key] {
					continue
				}
				seen[r.Key] = true
				Judge(&r)
				out = append(out, r)
			}
		}
	}
	return out
}

// Ambang bawaan per kelas: [warn, crit], arah "high" = buruk bila tinggi, "low" = buruk bila rendah.
type limit struct {
	Warn, Crit float64
	Low        bool
}

var Limits = map[string]limit{
	"cpu":         {80, 95, false},
	"memory":      {85, 95, false},
	"disk":        {85, 95, false},
	"temperature": {60, 75, false},
	"signal":      {-70, -78, true}, // dBm
	"ccq":         {70, 50, true},   // %
	"quality":     {70, 50, true},   // airMAX quality %
	"battery":     {50, 20, true},   // muatan baterai %
	"runtime":     {15, 5, true},    // menit
	"load":        {80, 95, false},  // beban UPS %
}

// Judge mengisi Status dari ambang kelas bila profil belum menentukannya.
func Judge(r *Reading) {
	if r.Status != "" {
		return
	}
	r.Status = "ok"
	l, ok := Limits[r.Class]
	if !ok || math.IsNaN(r.Value) {
		return
	}
	if r.Class == "temperature" && strings.Contains(strings.ToLower(r.Label), "cpu") {
		l = limit{75, 90, false}
	}
	switch {
	case !l.Low && r.Value >= l.Crit, l.Low && r.Value <= l.Crit:
		r.Status = "crit"
	case !l.Low && r.Value >= l.Warn, l.Low && r.Value <= l.Warn:
		r.Status = "warn"
	}
}

// ---------- pembantu untuk profil ----------

func num(v any) (float64, bool) {
	switch x := v.(type) {
	case float64:
		return x, true
	case string:
		var f float64
		if _, err := fmt.Sscan(strings.TrimSpace(x), &f); err == nil {
			return f, true
		}
	}
	return 0, false
}

func str(v any) string {
	switch x := v.(type) {
	case string:
		return x
	case float64:
		return fmt.Sprintf("%g", x)
	}
	return ""
}

// scalar membaca beberapa OID skalar sekaligus; kembalian dipetakan ke nama.
func scalars(q Q, m map[string]string) map[string]any {
	oids := make([]string, 0, len(m))
	for _, o := range m {
		oids = append(oids, o)
	}
	res, _ := q.Get(oids)
	out := map[string]any{}
	for name, o := range m {
		if v, ok := res[o]; ok {
			out[name] = v
		}
	}
	return out
}

// walks membaca beberapa kolom tabel; kembalian: nama kolom → (indeks → nilai).
func walks(q Q, cols map[string]string) map[string]map[string]any {
	out := map[string]map[string]any{}
	for name, base := range cols {
		res, _ := q.Walk(base)
		out[name] = res
	}
	return out
}

// sortedIdx mengurutkan indeks tabel secara numerik per bagian.
func sortedIdx(m map[string]any) []string {
	idx := make([]string, 0, len(m))
	for k := range m {
		idx = append(idx, k)
	}
	sort.Slice(idx, func(i, j int) bool {
		a, b := strings.Split(idx[i], "."), strings.Split(idx[j], ".")
		for k := 0; k < len(a) && k < len(b); k++ {
			var x, y int
			fmt.Sscan(a[k], &x)
			fmt.Sscan(b[k], &y)
			if x != y {
				return x < y
			}
		}
		return len(a) < len(b)
	})
	return idx
}

func slug(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	var b strings.Builder
	for _, r := range s {
		if r >= 'a' && r <= 'z' || r >= '0' && r <= '9' {
			b.WriteRune(r)
		} else if b.Len() > 0 && !strings.HasSuffix(b.String(), "-") {
			b.WriteRune('-')
		}
	}
	return strings.Trim(b.String(), "-")
}
