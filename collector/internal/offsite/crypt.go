package offsite

import (
	"bufio"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/binary"
	"errors"
	"fmt"
	"io"

	"golang.org/x/crypto/argon2"
)

// Enkripsi salinan backup di luar server (ISO/IEC 27001:2022 A.8.13, A.8.24).
//
// Format berkas .enc:
//
//	"AJENC1\n" (7 bita) | salt (16) | awalan nonce (8) | potongan...
//
// Kunci 256 bit = Argon2id(frasa sandi, salt, t=3, m=64 MiB, p=2). Isi dipotong 1 MiB; tiap potongan dienkripsi
// AES-256-GCM dengan nonce = awalan || nomor potongan (4 bita), dan data tambahan berisi penanda potongan terakhir,
// jadi potongan yang ditukar, dihapus, atau berkas yang terpotong ditolak saat dibuka. Potongan terakhir selalu ada
// (boleh kosong). Ukuran hasil bisa dihitung di muka, perlu untuk unggahan S3.

const (
	encMagic = "AJENC1\n"
	chunk    = 1 << 20
	tagSize  = 16
	header   = len(encMagic) + 16 + 8
)

// EncSuffix akhiran nama berkas terenkripsi.
const EncSuffix = ".enc"

func deriveKey(pass string, salt []byte) []byte {
	return argon2.IDKey([]byte(pass), salt, 3, 64*1024, 2, 32)
}

// EncryptedSize ukuran berkas terenkripsi untuk isi sebesar n bita.
func EncryptedSize(n int64) int64 {
	full := n / chunk
	return int64(header) + full*(chunk+tagSize) + (n%chunk + tagSize)
}

type sealer struct {
	aead   cipher.AEAD
	prefix []byte
}

func newSealer(pass string, salt, prefix []byte) (*sealer, error) {
	if pass == "" {
		return nil, errors.New("frasa sandi kosong")
	}
	b, err := aes.NewCipher(deriveKey(pass, salt))
	if err != nil {
		return nil, err
	}
	a, err := cipher.NewGCM(b)
	if err != nil {
		return nil, err
	}
	return &sealer{aead: a, prefix: prefix}, nil
}

func (s *sealer) nonce(i uint32) []byte {
	n := make([]byte, 12)
	copy(n, s.prefix)
	binary.BigEndian.PutUint32(n[8:], i)
	return n
}

func aad(last bool) []byte {
	if last {
		return []byte{1}
	}
	return []byte{0}
}

// Params salt dan awalan nonce satu berkas; dibuat sekali supaya dua kali enkripsi (hitung MD5, lalu unggah)
// menghasilkan bita yang sama.
type Params struct{ Salt, Prefix []byte }

func NewParams() (Params, error) {
	p := Params{Salt: make([]byte, 16), Prefix: make([]byte, 8)}
	if _, err := rand.Read(p.Salt); err != nil {
		return p, err
	}
	_, err := rand.Read(p.Prefix)
	return p, err
}

// Encrypt menulis isi src yang sudah dienkripsi ke dst.
func Encrypt(dst io.Writer, src io.Reader, pass string, p Params) error {
	s, err := newSealer(pass, p.Salt, p.Prefix)
	if err != nil {
		return err
	}
	if _, err := io.WriteString(dst, encMagic); err != nil {
		return err
	}
	if _, err := dst.Write(append(append([]byte{}, p.Salt...), p.Prefix...)); err != nil {
		return err
	}
	br := bufio.NewReaderSize(src, chunk)
	buf := make([]byte, chunk)
	out := make([]byte, 0, chunk+tagSize)
	for i := uint32(0); ; i++ {
		n, err := io.ReadFull(br, buf)
		if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) && !errors.Is(err, io.EOF) {
			return err
		}
		last := n < chunk
		if !last {
			// potongan penuh: terakhir bila tidak ada bita lagi sesudahnya
			if _, perr := br.Peek(1); perr != nil {
				if !errors.Is(perr, io.EOF) {
					return perr
				}
				// isi tepat kelipatan potongan: potongan ini penuh, lalu potongan terakhir kosong
				out = s.aead.Seal(out[:0], s.nonce(i), buf[:n], aad(false))
				if _, err := dst.Write(out); err != nil {
					return err
				}
				out = s.aead.Seal(out[:0], s.nonce(i+1), nil, aad(true))
				_, err := dst.Write(out)
				return err
			}
		}
		out = s.aead.Seal(out[:0], s.nonce(i), buf[:n], aad(last))
		if _, err := dst.Write(out); err != nil {
			return err
		}
		if last {
			return nil
		}
	}
}

// Decrypt membuka berkas .enc. Frasa salah, berkas rusak, atau berkas terpotong menghasilkan galat; isi yang sudah
// tertulis ke dst sebelum galat tidak boleh dipakai.
func Decrypt(dst io.Writer, src io.Reader, pass string) error {
	br := bufio.NewReaderSize(src, chunk+tagSize+1)
	h := make([]byte, header)
	if _, err := io.ReadFull(br, h); err != nil || string(h[:len(encMagic)]) != encMagic {
		return errors.New("bukan berkas backup terenkripsi ANTAREJA")
	}
	s, err := newSealer(pass, h[len(encMagic):len(encMagic)+16], h[len(encMagic)+16:])
	if err != nil {
		return err
	}
	buf := make([]byte, chunk+tagSize)
	var plain []byte
	for i := uint32(0); ; i++ {
		n, err := io.ReadFull(br, buf)
		if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) {
			if errors.Is(err, io.EOF) {
				return errors.New("berkas terpotong: potongan terakhir tidak ada")
			}
			return err
		}
		last := n < len(buf)
		if !last {
			if _, perr := br.Peek(1); errors.Is(perr, io.EOF) {
				last = true
			}
		}
		plain, err = s.aead.Open(plain[:0], s.nonce(i), buf[:n], aad(last))
		if err != nil {
			if i == 0 {
				return errors.New("frasa sandi salah atau berkas rusak")
			}
			return fmt.Errorf("berkas rusak atau terpotong di potongan %d", i)
		}
		if _, err := dst.Write(plain); err != nil {
			return err
		}
		if last {
			return nil
		}
	}
}
