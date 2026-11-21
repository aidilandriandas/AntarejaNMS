package secret

import "testing"

// Vektor uji dibuat dengan enkripsi Laravel (AES-256-CBC) memakai kunci uji di bawah.
const testKey = "base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=" // "0123456789abcdef0123456789abcdef"

func TestDecryptLaravelVectors(t *testing.T) {
	b, err := New(testKey)
	if err != nil {
		t.Fatal(err)
	}
	cases := map[string]string{
		// encryptString("public-tambang")
		"eyJpdiI6Ii85V3M2OC82T1VLSW85WFlkS0VUNWc9PSIsInZhbHVlIjoielB0R05kS0lrZjNJSFBuejJqei9zQT09IiwibWFjIjoiMTdmZTI5ZjVmNmI4NDNkZGJhZGZiNDU2NmMyZjNhZTc5MzkyOGI0ZDNiNjFmNmNhZDM5Zjc4M2QxNTgzZDIyYiIsInRhZyI6IiJ9": "public-tambang",
		// encrypt("rahasia SNMP v3!") — nilai terserialisasi PHP
		"eyJpdiI6IlpsNXA4OG1xS1RRRStzUFNnSTl2RkE9PSIsInZhbHVlIjoiRk0wRUhIVk5rL084TWZyTUJBSEVhNmE4UUxXQ3FVbXk1bGwrakh3MUZQST0iLCJtYWMiOiJiYjZmY2RhNGI1NTBhZmYzZmVjZTQwYjczNmJmMDU0MjVjOWQwOWQ1OTJjNWNkN2E0NDFlZGE3NWZmZGU4YjAwIiwidGFnIjoiIn0=": "rahasia SNMP v3!",
	}
	for enc, want := range cases {
		got, err := b.Decrypt(enc)
		if err != nil || got != want {
			t.Fatalf("Decrypt = %q, %v; ingin %q", got, err, want)
		}
	}
}

func TestRoundTripAndTamper(t *testing.T) {
	b, _ := New(testKey)
	for _, s := range []string{"", "a", "0123456789abcdef", "community-ber-spasi dan panjang sekali 1234567890"} {
		enc, err := b.Encrypt(s)
		if err != nil {
			t.Fatal(err)
		}
		got, err := b.Decrypt(enc)
		if err != nil || got != s {
			t.Fatalf("round trip %q -> %q, %v", s, got, err)
		}
	}
	other, _ := New("base64:ZmVkY2JhOTg3NjU0MzIxMGZlZGNiYTk4NzY1NDMyMTA=")
	enc, _ := b.Encrypt("public")
	if _, err := other.Decrypt(enc); err == nil {
		t.Fatal("kunci berbeda seharusnya gagal")
	}
	if _, err := b.Decrypt("bukan-payload"); err == nil {
		t.Fatal("payload sampah seharusnya gagal")
	}
	if _, err := New("base64:cGVuZGVr"); err == nil {
		t.Fatal("kunci pendek seharusnya ditolak")
	}
}
