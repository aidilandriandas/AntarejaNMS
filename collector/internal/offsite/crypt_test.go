package offsite

import (
	"bytes"
	"context"
	"crypto/rand"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestEncryptDecrypt(t *testing.T) {
	for _, n := range []int{0, 1, 1000, chunk - 1, chunk, chunk + 1, 3*chunk + 7, 2 * chunk} {
		src := make([]byte, n)
		_, _ = rand.Read(src)
		p, err := NewParams()
		if err != nil {
			t.Fatal(err)
		}
		var enc, enc2 bytes.Buffer
		if err := Encrypt(&enc, bytes.NewReader(src), "frasa-rahasia-panjang", p); err != nil {
			t.Fatal(n, err)
		}
		if int64(enc.Len()) != EncryptedSize(int64(n)) {
			t.Fatal("ukuran tidak sesuai perkiraan", n, enc.Len(), EncryptedSize(int64(n)))
		}
		// parameter sama: bita sama (MD5 dihitung dulu, lalu diunggah)
		_ = Encrypt(&enc2, bytes.NewReader(src), "frasa-rahasia-panjang", p)
		if !bytes.Equal(enc.Bytes(), enc2.Bytes()) {
			t.Fatal("enkripsi ulang harus identik", n)
		}
		if bytes.Contains(enc.Bytes(), src[:min(n, 64)]) && n >= 64 {
			t.Fatal("isi asli tidak boleh terbaca")
		}
		var out bytes.Buffer
		if err := Decrypt(&out, bytes.NewReader(enc.Bytes()), "frasa-rahasia-panjang"); err != nil || !bytes.Equal(out.Bytes(), src) {
			t.Fatal("buka gagal", n, err)
		}
		if err := Decrypt(&out, bytes.NewReader(enc.Bytes()), "frasa-salah"); err == nil || !strings.Contains(err.Error(), "frasa sandi salah") {
			t.Fatal("frasa salah harus ditolak", n, err)
		}
		// terpotong di batas potongan atau di tengah
		b := enc.Bytes()
		for _, cut := range []int{len(b) - 1, len(b) - tagSize, header + chunk + tagSize} {
			if cut <= header || cut >= len(b) {
				continue
			}
			if err := Decrypt(&out, bytes.NewReader(b[:cut]), "frasa-rahasia-panjang"); err == nil {
				t.Fatal("berkas terpotong harus ditolak", n, cut)
			}
		}
		// satu bita diubah
		if len(b) > header+5 {
			bad := append([]byte{}, b...)
			bad[header+3] ^= 1
			if err := Decrypt(&out, bytes.NewReader(bad), "frasa-rahasia-panjang"); err == nil {
				t.Fatal("isi diubah harus ditolak", n)
			}
		}
	}
	if err := Decrypt(&bytes.Buffer{}, strings.NewReader("PGDMP biasa"), "x"); err == nil {
		t.Fatal("berkas biasa bukan .enc")
	}
}

func TestCopyEncryptedDanRetensi(t *testing.T) {
	dir := t.TempDir()
	src := bytes.Repeat([]byte("PGDMP data rahasia jaringan "), 90000) // > 2 MiB
	_ = os.WriteFile(filepath.Join(dir, "antareja-20261127-013000.dump"), src, 0o600)
	_ = os.WriteFile(filepath.Join(dir, "antareja-20261127-013000.json"), []byte(`{"ok":true}`), 0o600)
	mt := &memTarget{files: map[string][]byte{
		"antareja-20261120-013000.dump.enc": []byte("lama"), "antareja-20261120-013000.json.enc": []byte("lama"),
		"antareja-20261126-013000.dump": []byte("polos lama"),
	}}
	r := &Runner{Dir: dir, pass: "frasa-rahasia-panjang"}
	if err := r.copyOne(context.Background(), mt, "antareja-20261127-013000.dump"); err != nil {
		t.Fatal(err)
	}
	enc, ok := mt.files["antareja-20261127-013000.dump.enc"]
	if !ok || mt.files["antareja-20261127-013000.json.enc"] == nil || mt.files["antareja-20261127-013000.dump"] != nil {
		t.Fatal("yang diunggah harus .enc saja")
	}
	if bytes.Contains(enc, []byte("PGDMP")) {
		t.Fatal("isi polos tidak boleh sampai ke tujuan")
	}
	var out bytes.Buffer
	if err := Decrypt(&out, bytes.NewReader(enc), "frasa-rahasia-panjang"); err != nil || !bytes.Equal(out.Bytes(), src) {
		t.Fatal("salinan harus bisa dipulihkan", err)
	}
	if err := prune(context.Background(), mt, 2); err != nil {
		t.Fatal(err)
	}
	if _, ok := mt.files["antareja-20261120-013000.dump.enc"]; ok || mt.files["antareja-20261120-013000.json.enc"] != nil {
		t.Fatal("salinan terenkripsi tertua harus terhapus beserta manifest")
	}
	if mt.files["antareja-20261126-013000.dump"] == nil {
		t.Fatal("dua terbaru tetap ada")
	}
}
