package sitecollector

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"os"
	"strings"
	"testing"
)

func TestSpoolUrutDanBatasUkuran(t *testing.T) {
	s := &Spool{Dir: t.TempDir(), MaxBytes: 1 << 20}
	for i := 0; i < 5; i++ {
		if _, err := s.Append(Batch{Version: string(rune('a' + i))}); err != nil {
			t.Fatal(err)
		}
	}
	files := s.Files()
	if len(files) != 5 {
		t.Fatalf("5 berkas, dapat %d", len(files))
	}
	raw, _ := s.Read(files[0])
	zr, err := gzip.NewReader(bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	var b Batch
	if err := json.NewDecoder(zr).Decode(&b); err != nil || b.Version != "a" {
		t.Fatalf("berkas terlama dibaca lebih dulu: %+v %v", b, err)
	}
	if st, _ := os.Stat(files[0]); st.Mode().Perm() != 0o600 {
		t.Fatalf("berkas antrean hanya bisa dibaca pemilik, dapat %v", st.Mode().Perm())
	}
	// Batas ukuran: kiriman terlama dibuang, yang terbaru selalu disimpan.
	s.MaxBytes = 1
	dropped, _ := s.Append(Batch{Version: "z"})
	if dropped != 5 || len(s.Files()) != 1 {
		t.Fatalf("antrean penuh membuang yang terlama: dibuang %d, sisa %d", dropped, len(s.Files()))
	}
	s.Reject(s.Files()[0])
	if len(s.Files()) != 0 {
		t.Fatal("berkas yang ditolak keluar dari antrean")
	}
}

func TestKonfigurasiDiDiskTanpaKredensial(t *testing.T) {
	devs := []DeviceConfig{{ID: 1, Host: "10.0.0.1", SNMP: &Credentials{Version: "2c", Community: "RahasiaKomunitas"}}}
	raw, _ := json.Marshal(withoutSecrets(devs))
	if strings.Contains(string(raw), "RahasiaKomunitas") || devs[0].SNMP == nil {
		t.Fatalf("salinan disk tanpa kredensial, salinan memori tetap utuh: %s", raw)
	}
}
