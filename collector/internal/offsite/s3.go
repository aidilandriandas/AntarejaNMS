package offsite

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/xml"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

// S3 tujuan S3 atau yang kompatibel (MinIO, Wasabi, Ceph RGW). Alamat path-style: endpoint/bucket/kunci.
type S3 struct {
	Endpoint  string // https://s3.ap-southeast-3.amazonaws.com atau https://minio.kantor:9000
	Region    string
	Bucket    string
	Prefix    string // mis. "antareja/" (boleh kosong)
	AccessKey string
	SecretKey string
	HTTP      *http.Client
	now       func() time.Time
}

const emptySHA = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

func (s *S3) client() *http.Client {
	if s.HTTP != nil {
		return s.HTTP
	}
	return &http.Client{Timeout: 2 * time.Hour}
}

func (s *S3) objURL(key string) (*url.URL, error) {
	u, err := url.Parse(strings.TrimRight(s.Endpoint, "/"))
	if err != nil || u.Host == "" || (u.Scheme != "https" && u.Scheme != "http") {
		return nil, fmt.Errorf("endpoint S3 tidak valid")
	}
	p := "/" + s.Bucket
	if key != "" {
		p += "/" + s.Prefix + key
	}
	u.Path = p
	return u, nil
}

// sign menambahkan header tanda tangan AWS Signature Version 4.
func (s *S3) sign(req *http.Request, payloadHash string) {
	t := time.Now().UTC()
	if s.now != nil {
		t = s.now().UTC()
	}
	amzDate := t.Format("20060102T150405Z")
	day := t.Format("20060102")
	req.Header.Set("x-amz-date", amzDate)
	req.Header.Set("x-amz-content-sha256", payloadHash)
	req.Header.Set("Authorization", signature(req, s.AccessKey, s.SecretKey, s.Region, "s3", amzDate, day, payloadHash))
}

// signature menghitung header Authorization SigV4 dari request (header yang ikut ditandatangani: host dan semua x-amz-*,
// content-md5, range). Dipisah supaya bisa diuji dengan contoh resmi AWS.
func signature(req *http.Request, ak, sk, region, service, amzDate, day, payloadHash string) string {
	hdr := map[string]string{"host": req.Host}
	if hdr["host"] == "" {
		hdr["host"] = req.URL.Host
	}
	for k, v := range req.Header {
		lk := strings.ToLower(k)
		if strings.HasPrefix(lk, "x-amz-") || lk == "content-md5" || lk == "range" || lk == "content-type" {
			hdr[lk] = strings.TrimSpace(strings.Join(v, ","))
		}
	}
	names := make([]string, 0, len(hdr))
	for k := range hdr {
		names = append(names, k)
	}
	sort.Strings(names)
	var ch strings.Builder
	for _, k := range names {
		ch.WriteString(k + ":" + hdr[k] + "\n")
	}
	signed := strings.Join(names, ";")
	canon := strings.Join([]string{req.Method, encodePath(req.URL.EscapedPath()), canonQuery(req.URL.Query()), ch.String(), signed, payloadHash}, "\n")
	scope := day + "/" + region + "/" + service + "/aws4_request"
	sum := sha256.Sum256([]byte(canon))
	toSign := "AWS4-HMAC-SHA256\n" + amzDate + "\n" + scope + "\n" + hex.EncodeToString(sum[:])
	key := hmacSHA([]byte("AWS4"+sk), day)
	key = hmacSHA(key, region)
	key = hmacSHA(key, service)
	key = hmacSHA(key, "aws4_request")
	sig := hex.EncodeToString(hmacSHA(key, toSign))
	return "AWS4-HMAC-SHA256 Credential=" + ak + "/" + scope + ", SignedHeaders=" + signed + ", Signature=" + sig
}

func hmacSHA(key []byte, data string) []byte {
	m := hmac.New(sha256.New, key)
	m.Write([]byte(data))
	return m.Sum(nil)
}

// encodePath: jalur sudah di-escape oleh net/url; S3 meminta karakter selain unreserved dan '/' di-encode persen.
func encodePath(p string) string {
	if p == "" {
		return "/"
	}
	un, err := url.PathUnescape(p)
	if err != nil {
		return p
	}
	var b strings.Builder
	for _, c := range []byte(un) {
		switch {
		case c >= 'A' && c <= 'Z', c >= 'a' && c <= 'z', c >= '0' && c <= '9', c == '-', c == '_', c == '.', c == '~', c == '/':
			b.WriteByte(c)
		default:
			fmt.Fprintf(&b, "%%%02X", c)
		}
	}
	return b.String()
}

func canonQuery(q url.Values) string {
	keys := make([]string, 0, len(q))
	for k := range q {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var parts []string
	for _, k := range keys {
		vs := append([]string(nil), q[k]...)
		sort.Strings(vs)
		for _, v := range vs {
			parts = append(parts, awsEscape(k)+"="+awsEscape(v))
		}
	}
	return strings.Join(parts, "&")
}

func awsEscape(s string) string {
	return strings.ReplaceAll(url.QueryEscape(s), "+", "%20")
}

func (s *S3) do(ctx context.Context, method, key string, query url.Values, body io.Reader, size int64, hdr map[string]string) (*http.Response, error) {
	u, err := s.objURL(key)
	if err != nil {
		return nil, err
	}
	if query != nil {
		u.RawQuery = query.Encode()
	}
	req, err := http.NewRequestWithContext(ctx, method, u.String(), body)
	if err != nil {
		return nil, err
	}
	if body != nil {
		req.ContentLength = size
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	payload := emptySHA
	if body != nil {
		payload = "UNSIGNED-PAYLOAD" // isi dijaga Content-MD5 (diperiksa server) dan TLS
	}
	s.sign(req, payload)
	resp, err := s.client().Do(req)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode/100 != 2 {
		defer resp.Body.Close()
		var e struct {
			Code    string `xml:"Code"`
			Message string `xml:"Message"`
		}
		_ = xml.NewDecoder(io.LimitReader(resp.Body, 8192)).Decode(&e)
		return nil, fmt.Errorf("S3 menjawab %d %s %s", resp.StatusCode, e.Code, e.Message)
	}
	return resp, nil
}

// Put mengunggah satu objek; md5b64 = Content-MD5 (server menolak bila isi berbeda).
func (s *S3) Put(ctx context.Context, name string, r io.Reader, size int64, md5b64 string) error {
	resp, err := s.do(ctx, http.MethodPut, name, nil, r, size, map[string]string{"Content-MD5": md5b64, "Content-Type": "application/octet-stream"})
	if err != nil {
		return err
	}
	resp.Body.Close()
	// ETag unggahan tunggal = MD5 isi yang diterima server (S3, MinIO). Dicek juga di sisi kita, tidak hanya
	// mengandalkan server memeriksa Content-MD5 (tidak semua server kompatibel S3 memeriksanya).
	etag := strings.Trim(resp.Header.Get("ETag"), `"`)
	if want, err := base64.StdEncoding.DecodeString(md5b64); err == nil && len(etag) == 32 && !strings.EqualFold(etag, hex.EncodeToString(want)) {
		_ = s.Delete(ctx, name)
		return fmt.Errorf("isi yang diterima server berbeda (ETag %s), unggahan dibatalkan", etag)
	}
	return nil
}

// List nama objek di bawah prefix (tanpa prefix).
func (s *S3) List(ctx context.Context) ([]string, error) {
	var out []string
	token := ""
	for page := 0; page < 50; page++ {
		q := url.Values{"list-type": {"2"}, "prefix": {s.Prefix}}
		if token != "" {
			q.Set("continuation-token", token)
		}
		resp, err := s.do(ctx, http.MethodGet, "", q, nil, 0, nil)
		if err != nil {
			return nil, err
		}
		var res struct {
			Contents []struct {
				Key string `xml:"Key"`
			} `xml:"Contents"`
			IsTruncated bool   `xml:"IsTruncated"`
			Next        string `xml:"NextContinuationToken"`
		}
		err = xml.NewDecoder(io.LimitReader(resp.Body, 16<<20)).Decode(&res)
		resp.Body.Close()
		if err != nil {
			return nil, err
		}
		for _, c := range res.Contents {
			if n := strings.TrimPrefix(c.Key, s.Prefix); !strings.Contains(n, "/") {
				out = append(out, n)
			}
		}
		if !res.IsTruncated {
			break
		}
		token = res.Next
	}
	return out, nil
}

func (s *S3) Delete(ctx context.Context, name string) error {
	resp, err := s.do(ctx, http.MethodDelete, name, nil, nil, 0, nil)
	if err != nil {
		return err
	}
	resp.Body.Close()
	return nil
}

func (s *S3) Close() error { return nil }

func (s *S3) Describe() string {
	return "s3 " + strings.TrimRight(s.Endpoint, "/") + "/" + s.Bucket + "/" + s.Prefix
}
