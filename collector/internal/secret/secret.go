// Package secret mengenkripsi/mendekripsi kredensial perangkat.
//
// Format payload sengaja dibuat sama dengan enkripsi AES-256-CBC milik Laravel
// (base64 dari JSON {"iv","value","mac","tag"}; mac = HMAC-SHA256(iv+value) dalam hex),
// sehingga web (Laravel) dan collector (Go) dapat berbagi kunci ANTAREJA_APP_KEY yang sama.
// Implementasi ini ditulis dari spesifikasi format tersebut, bukan disalin dari kode Laravel.
package secret

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

type Box struct{ key []byte }

// Key mengembalikan kunci mentah (untuk menurunkan token API internal).
func (b *Box) Key() []byte { return b.key }

type payload struct {
	IV    string `json:"iv"`
	Value string `json:"value"`
	MAC   string `json:"mac"`
	Tag   string `json:"tag"`
}

// New menerima kunci berformat "base64:<32 byte>".
func New(appKey string) (*Box, error) {
	raw := appKey
	if strings.HasPrefix(raw, "base64:") {
		b, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(raw, "base64:"))
		if err != nil {
			return nil, fmt.Errorf("ANTAREJA_APP_KEY tidak valid: %w", err)
		}
		raw = string(b)
	}
	if len(raw) != 32 {
		return nil, errors.New("ANTAREJA_APP_KEY harus 32 byte (AES-256)")
	}
	return &Box{key: []byte(raw)}, nil
}

func (b *Box) mac(iv, value string) []byte {
	h := hmac.New(sha256.New, b.key)
	h.Write([]byte(iv + value))
	return h.Sum(nil)
}

// Encrypt mengenkripsi teks polos (tanpa serialisasi PHP — setara encryptString).
func (b *Box) Encrypt(plain string) (string, error) {
	iv := make([]byte, aes.BlockSize)
	if _, err := rand.Read(iv); err != nil {
		return "", err
	}
	block, _ := aes.NewCipher(b.key)
	pad := aes.BlockSize - len(plain)%aes.BlockSize
	data := append([]byte(plain), bytes.Repeat([]byte{byte(pad)}, pad)...)
	out := make([]byte, len(data))
	cipher.NewCBCEncrypter(block, iv).CryptBlocks(out, data)
	p := payload{IV: base64.StdEncoding.EncodeToString(iv), Value: base64.StdEncoding.EncodeToString(out)}
	p.MAC = hex.EncodeToString(b.mac(p.IV, p.Value))
	j, _ := json.Marshal(p)
	return base64.StdEncoding.EncodeToString(j), nil
}

var phpString = regexp.MustCompile(`^s:(\d+):"(.*)";$`)

// Decrypt membuka payload. Nilai yang diserialisasi PHP (s:N:"...";) dibuka otomatis.
func (b *Box) Decrypt(enc string) (string, error) {
	j, err := base64.StdEncoding.DecodeString(enc)
	if err != nil {
		return "", errors.New("payload bukan base64")
	}
	var p payload
	if err := json.Unmarshal(j, &p); err != nil {
		return "", errors.New("payload bukan JSON")
	}
	want, err := hex.DecodeString(p.MAC)
	if err != nil || !hmac.Equal(want, b.mac(p.IV, p.Value)) {
		return "", errors.New("MAC tidak cocok (kunci berbeda atau data rusak)")
	}
	iv, err1 := base64.StdEncoding.DecodeString(p.IV)
	data, err2 := base64.StdEncoding.DecodeString(p.Value)
	if err1 != nil || err2 != nil || len(iv) != aes.BlockSize || len(data) == 0 || len(data)%aes.BlockSize != 0 {
		return "", errors.New("payload rusak")
	}
	block, _ := aes.NewCipher(b.key)
	out := make([]byte, len(data))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(out, data)
	pad := int(out[len(out)-1])
	if pad < 1 || pad > aes.BlockSize || pad > len(out) || !bytes.Equal(out[len(out)-pad:], bytes.Repeat([]byte{byte(pad)}, pad)) {
		return "", errors.New("padding tidak valid")
	}
	s := string(out[:len(out)-pad])
	if m := phpString.FindStringSubmatch(s); m != nil {
		if n, _ := strconv.Atoi(m[1]); n == len(m[2]) {
			return m[2], nil
		}
	}
	return s, nil
}
