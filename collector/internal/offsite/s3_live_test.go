package offsite

import (
	"context"
	"crypto/md5"
	"encoding/base64"
	"os"
	"strings"
	"testing"
)

// Uji S3 sungguhan (MinIO/AWS/moto): set ANTAREJA_TEST_S3_ENDPOINT, bucket "nms-backup" sudah ada.
func TestS3Langsung(t *testing.T) {
	ep := os.Getenv("ANTAREJA_TEST_S3_ENDPOINT")
	if ep == "" {
		t.Skip("set ANTAREJA_TEST_S3_ENDPOINT")
	}
	ctx := context.Background()
	s := &S3{Endpoint: ep, Region: "us-east-1", Bucket: "nms-backup", Prefix: "antareja/", AccessKey: "uji", SecretKey: "uji-rahasia"}
	body := strings.Repeat("isi backup ", 1000)
	sum := md5.Sum([]byte(body))
	good := base64.StdEncoding.EncodeToString(sum[:])
	for _, n := range []string{"antareja-20261101-013000.dump", "antareja-20261102-013000.dump", "antareja-20261103-013000.dump", "antareja-20261103-013000.json"} {
		if err := s.Put(ctx, n, strings.NewReader(body), int64(len(body)), good); err != nil {
			t.Fatal(err)
		}
	}
	// isi tidak sesuai Content-MD5: server wajib menolak
	if err := s.Put(ctx, "rusak.dump", strings.NewReader(body+"x"), int64(len(body)+1), good); err == nil {
		t.Fatal("isi rusak seharusnya ditolak server")
	}
	if err := prune(ctx, s, 2); err != nil {
		t.Fatal(err)
	}
	names, err := s.List(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(names, ",") != "antareja-20261102-013000.dump,antareja-20261103-013000.dump,antareja-20261103-013000.json" {
		t.Fatalf("retensi 2: %v", names)
	}
	for _, n := range names {
		_ = s.Delete(ctx, n)
	}
}
