package offsite

import (
	"net/http"
	"strings"
	"testing"
)

// Contoh resmi AWS "GET Object" (dokumentasi Signature Version 4 untuk S3, bagian contoh perhitungan):
// kunci rahasia, tanggal, dan header di bawah menghasilkan tanda tangan yang diketahui.
func TestSigV4ContohAWS(t *testing.T) {
	req, _ := http.NewRequest(http.MethodGet, "https://examplebucket.s3.amazonaws.com/test.txt", nil)
	req.Header.Set("Range", "bytes=0-9")
	req.Header.Set("x-amz-content-sha256", emptySHA)
	req.Header.Set("x-amz-date", "20130524T000000Z")
	got := signature(req, "AKIAIOSFODNN7EXAMPLE", "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY", "us-east-1", "s3", "20130524T000000Z", "20130524", emptySHA)
	want := "Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41"
	if !strings.HasSuffix(got, want) || !strings.Contains(got, "SignedHeaders=host;range;x-amz-content-sha256;x-amz-date") {
		t.Fatalf("tanda tangan tidak sama dengan contoh AWS:\n%s", got)
	}
}

func TestEncodePath(t *testing.T) {
	if p := encodePath("/bucket/antareja/a%20b+c.dump"); p != "/bucket/antareja/a%20b%2Bc.dump" {
		t.Fatal(p)
	}
}
