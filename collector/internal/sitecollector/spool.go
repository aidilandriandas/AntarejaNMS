package sitecollector

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

// Spool antrean kiriman di disk site. Satu berkas = satu kiriman (JSON, gzip), nama berurutan waktu, jadi
// pengiriman ulang selalu dari yang terlama. Bila melebihi batas ukuran, yang terlama dibuang (dengan log).
type Spool struct {
	Dir      string
	MaxBytes int64

	mu   sync.Mutex
	last int64
}

const spoolExt = ".json.gz"

func (s *Spool) init() error {
	return os.MkdirAll(s.Dir, 0o700)
}

// Append menulis satu kiriman. Mengembalikan jumlah berkas lama yang dibuang karena batas ukuran.
func (s *Spool) Append(b Batch) (dropped int, err error) {
	if err := s.init(); err != nil {
		return 0, err
	}
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	if err := json.NewEncoder(zw).Encode(b); err != nil {
		return 0, err
	}
	if err := zw.Close(); err != nil {
		return 0, err
	}
	s.mu.Lock()
	n := time.Now().UnixNano()
	if n <= s.last {
		n = s.last + 1
	}
	s.last = n
	s.mu.Unlock()
	name := filepath.Join(s.Dir, fmt.Sprintf("%020d%s", n, spoolExt))
	tmp := name + ".tmp"
	if err := os.WriteFile(tmp, buf.Bytes(), 0o600); err != nil {
		return 0, err
	}
	if err := os.Rename(tmp, name); err != nil {
		return 0, err
	}
	return s.trim()
}

// Files berkas antrean dari yang terlama.
func (s *Spool) Files() []string {
	ents, err := os.ReadDir(s.Dir)
	if err != nil {
		return nil
	}
	var out []string
	for _, e := range ents {
		if !e.IsDir() && strings.HasSuffix(e.Name(), spoolExt) {
			out = append(out, filepath.Join(s.Dir, e.Name()))
		}
	}
	sort.Strings(out)
	return out
}

func (s *Spool) trim() (int, error) {
	files := s.Files()
	var total int64
	sizes := make([]int64, len(files))
	for i, f := range files {
		if st, err := os.Stat(f); err == nil {
			sizes[i] = st.Size()
			total += st.Size()
		}
	}
	dropped := 0
	for i := 0; total > s.MaxBytes && i < len(files)-1; i++ {
		if err := os.Remove(files[i]); err == nil {
			total -= sizes[i]
			dropped++
		}
	}
	return dropped, nil
}

// Read membaca isi berkas apa adanya (gzip) untuk dikirim.
func (s *Spool) Read(path string) ([]byte, error) { return os.ReadFile(path) }

// Done menghapus berkas yang sudah diterima pusat.
func (s *Spool) Done(path string) error { return os.Remove(path) }

// Reject memindahkan berkas yang ditolak pusat secara permanen ke subfolder rejected (disimpan 20 terakhir).
func (s *Spool) Reject(path string) {
	dir := filepath.Join(s.Dir, "rejected")
	if os.MkdirAll(dir, 0o700) != nil {
		_ = os.Remove(path)
		return
	}
	_ = os.Rename(path, filepath.Join(dir, filepath.Base(path)))
	ents, _ := os.ReadDir(dir)
	for i := 0; i < len(ents)-20; i++ {
		_ = os.Remove(filepath.Join(dir, ents[i].Name()))
	}
}
