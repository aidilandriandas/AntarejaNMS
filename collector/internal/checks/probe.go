// Package checks menjalankan cek layanan (HTTP(S), port TCP, DNS) dari collector pusat dan menilai statusnya.
// Hanya membaca: HTTP memakai GET tanpa kredensial dan tanpa mengikuti pengalihan, TCP hanya membuka lalu menutup
// koneksi, DNS hanya bertanya.
package checks

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Spec definisi satu cek.
type Spec struct {
	ID           int64
	Type         string // http | tcp | dns
	Host         string // host perangkat (tcp, dns)
	URL          string
	Port         int
	Query        string
	ExpectStatus string
	ExpectText   string
	ExpectAnswer string
	VerifyTLS    bool
	Timeout      time.Duration
}

// Result hasil satu percobaan.
type Result struct {
	OK         bool
	MS         float64
	Error      string
	CertExpiry *time.Time // http(s): masa berlaku sertifikat server
	Status     int        // http: kode status
}

// Run menjalankan satu cek.
func Run(ctx context.Context, s Spec) Result {
	if s.Timeout <= 0 {
		s.Timeout = 10 * time.Second
	}
	ctx, cancel := context.WithTimeout(ctx, s.Timeout)
	defer cancel()
	start := time.Now()
	var r Result
	switch s.Type {
	case "http":
		r = runHTTP(ctx, s)
	case "tcp":
		r = runTCP(ctx, s)
	case "dns":
		r = runDNS(ctx, s)
	default:
		r.Error = "jenis cek tidak dikenal"
	}
	r.MS = float64(time.Since(start).Microseconds()) / 1000
	if ctx.Err() != nil && !r.OK && r.Error == "" {
		r.Error = "tidak menjawab dalam " + s.Timeout.String()
	}
	return r
}

func runHTTP(ctx context.Context, s Spec) Result {
	var r Result
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, s.URL, nil)
	if err != nil || (req.URL.Scheme != "http" && req.URL.Scheme != "https") {
		r.Error = "URL tidak valid"
		return r
	}
	req.Header.Set("User-Agent", "ANTAREJA-NMS-check/1")
	tr := &http.Transport{
		Proxy:             nil,                                                                         // cek dari jaringan collector sendiri, bukan lewat proxy
		TLSClientConfig:   &tls.Config{InsecureSkipVerify: !s.VerifyTLS, MinVersion: tls.VersionTLS12}, //nolint:gosec // dipilih engineer per cek (perangkat dengan sertifikat bawaan)
		DisableKeepAlives: true,
	}
	defer tr.CloseIdleConnections()
	c := &http.Client{Transport: tr, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := c.Do(req)
	if err != nil {
		r.Error = friendly(err)
		return r
	}
	defer resp.Body.Close()
	r.Status = resp.StatusCode
	if resp.TLS != nil && len(resp.TLS.PeerCertificates) > 0 {
		t := resp.TLS.PeerCertificates[0].NotAfter
		r.CertExpiry = &t
	}
	if !statusOK(resp.StatusCode, s.ExpectStatus) {
		r.Error = fmt.Sprintf("kode HTTP %d (diharapkan %s)", resp.StatusCode, orDefault(s.ExpectStatus, "200-399"))
		return r
	}
	if s.ExpectText != "" {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		if !strings.Contains(string(body), s.ExpectText) {
			r.Error = "teks \"" + trim(s.ExpectText, 60) + "\" tidak ada di halaman"
			return r
		}
	}
	r.OK = true
	return r
}

func runTCP(ctx context.Context, s Spec) Result {
	var d net.Dialer
	conn, err := d.DialContext(ctx, "tcp", net.JoinHostPort(s.Host, strconv.Itoa(s.Port)))
	if err != nil {
		return Result{Error: friendly(err)}
	}
	_ = conn.Close()
	return Result{OK: true}
}

func runDNS(ctx context.Context, s Spec) Result {
	port := s.Port
	if port == 0 {
		port = 53
	}
	server := net.JoinHostPort(s.Host, strconv.Itoa(port))
	res := &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, network, server)
	}}
	addrs, err := res.LookupHost(ctx, s.Query)
	if err != nil {
		var de *net.DNSError
		if errors.As(err, &de) && de.IsNotFound {
			return Result{Error: "nama " + s.Query + " tidak ditemukan (NXDOMAIN)"}
		}
		return Result{Error: friendly(err)}
	}
	if s.ExpectAnswer != "" {
		for _, a := range addrs {
			if a == s.ExpectAnswer {
				return Result{OK: true}
			}
		}
		return Result{Error: "jawaban " + strings.Join(addrs, ", ") + " tidak memuat " + s.ExpectAnswer}
	}
	return Result{OK: true}
}

// statusOK: daftar dipisah koma, tiap bagian satu kode atau rentang "a-b". Kosong = 200-399.
func statusOK(code int, expect string) bool {
	expect = strings.TrimSpace(expect)
	if expect == "" {
		return code >= 200 && code < 400
	}
	for _, part := range strings.Split(expect, ",") {
		part = strings.TrimSpace(part)
		if a, b, ok := strings.Cut(part, "-"); ok {
			lo, e1 := strconv.Atoi(strings.TrimSpace(a))
			hi, e2 := strconv.Atoi(strings.TrimSpace(b))
			if e1 == nil && e2 == nil && code >= lo && code <= hi {
				return true
			}
		} else if n, err := strconv.Atoi(part); err == nil && n == code {
			return true
		}
	}
	return false
}

// ValidStatusExpr memeriksa format expect_status.
func ValidStatusExpr(expect string) bool {
	if strings.TrimSpace(expect) == "" {
		return true
	}
	for _, part := range strings.Split(expect, ",") {
		part = strings.TrimSpace(part)
		a, b, isRange := strings.Cut(part, "-")
		if !isRange {
			b = a
		}
		lo, e1 := strconv.Atoi(strings.TrimSpace(a))
		hi, e2 := strconv.Atoi(strings.TrimSpace(b))
		if e1 != nil || e2 != nil || lo < 100 || hi > 599 || lo > hi {
			return false
		}
	}
	return true
}

func friendly(err error) string {
	msg := err.Error()
	var ne net.Error
	switch {
	case errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &ne) && ne.Timeout()):
		return "tidak menjawab (batas waktu habis)"
	case strings.Contains(msg, "connection refused"):
		return "koneksi ditolak (layanan tidak berjalan di port itu)"
	case strings.Contains(msg, "no route to host") || strings.Contains(msg, "network is unreachable"):
		return "tidak ada rute ke host"
	case strings.Contains(msg, "certificate"):
		return "sertifikat HTTPS ditolak: " + trim(msg[strings.LastIndex(msg, ": ")+2:], 160)
	case strings.Contains(msg, "no such host"):
		return "nama host tidak bisa di-resolve"
	}
	return trim(msg, 200)
}

func trim(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

func orDefault(s, d string) string {
	if strings.TrimSpace(s) == "" {
		return d
	}
	return s
}
