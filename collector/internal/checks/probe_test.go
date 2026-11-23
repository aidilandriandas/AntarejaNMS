package checks

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestHTTP(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/ok":
			_, _ = w.Write([]byte("<h1>FMS Dispatch siap</h1>"))
		case "/pindah":
			http.Redirect(w, r, "/login", http.StatusFound)
		default:
			w.WriteHeader(http.StatusServiceUnavailable)
		}
	}))
	defer srv.Close()
	ctx := context.Background()
	if r := Run(ctx, Spec{Type: "http", URL: srv.URL + "/ok", ExpectText: "Dispatch siap"}); !r.OK || r.Status != 200 {
		t.Fatalf("halaman normal: %+v", r)
	}
	if r := Run(ctx, Spec{Type: "http", URL: srv.URL + "/ok", ExpectText: "Login"}); r.OK || !strings.Contains(r.Error, "tidak ada") {
		t.Fatalf("teks wajib tidak ada: %+v", r)
	}
	if r := Run(ctx, Spec{Type: "http", URL: srv.URL + "/mati"}); r.OK || !strings.Contains(r.Error, "503") {
		t.Fatalf("503 gagal: %+v", r)
	}
	if r := Run(ctx, Spec{Type: "http", URL: srv.URL + "/pindah"}); !r.OK || r.Status != 302 {
		t.Fatalf("pengalihan tidak diikuti dan 302 masuk 200-399: %+v", r)
	}
	if r := Run(ctx, Spec{Type: "http", URL: srv.URL + "/pindah", ExpectStatus: "200"}); r.OK {
		t.Fatal("302 ditolak bila yang diharapkan 200")
	}
	if r := Run(ctx, Spec{Type: "http", URL: "file:///etc/passwd"}); r.OK || r.Error != "URL tidak valid" {
		t.Fatalf("hanya http/https: %+v", r)
	}
}

func TestHTTPSSertifikat(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) }))
	defer srv.Close()
	ctx := context.Background()
	if r := Run(ctx, Spec{Type: "http", URL: srv.URL, VerifyTLS: true}); r.OK || !strings.Contains(r.Error, "sertifikat") {
		t.Fatalf("sertifikat uji tidak tepercaya harus ditolak: %+v", r)
	}
	r := Run(ctx, Spec{Type: "http", URL: srv.URL, VerifyTLS: false})
	if !r.OK || r.CertExpiry == nil || r.CertExpiry.Before(time.Now()) {
		t.Fatalf("tanpa verifikasi tetap terbaca masa berlakunya: %+v", r)
	}
}

func TestTCP(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := ln.Addr().(*net.TCPAddr).Port
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			c.Close()
		}
	}()
	ctx := context.Background()
	if r := Run(ctx, Spec{Type: "tcp", Host: "127.0.0.1", Port: port}); !r.OK {
		t.Fatalf("port terbuka: %+v", r)
	}
	ln.Close()
	if r := Run(ctx, Spec{Type: "tcp", Host: "127.0.0.1", Port: port, Timeout: 2 * time.Second}); r.OK || !strings.Contains(r.Error, "ditolak") {
		t.Fatalf("port tertutup: %+v", r)
	}
}

func TestStatusDanPenilaian(t *testing.T) {
	cases := map[string][]int{"": {200, 301, 399}, "200": {200}, "200-299,401": {204, 401}}
	for expr, ok := range cases {
		for _, c := range ok {
			if !statusOK(c, expr) {
				t.Errorf("%d harus lolos %q", c, expr)
			}
		}
	}
	if statusOK(404, "") || statusOK(500, "200-299,401") {
		t.Error("kode di luar daftar harus gagal")
	}
	for _, bad := range []string{"abc", "299-200", "700", "200-"} {
		if ValidStatusExpr(bad) {
			t.Errorf("%q harus ditolak", bad)
		}
	}
	slow := 500
	if s, f := Judge("up", 0, Result{Error: "x"}, nil); s != "up" || f != 1 {
		t.Errorf("satu kali gagal belum down: %s %d", s, f)
	}
	if s, _ := Judge("up", 1, Result{Error: "x"}, nil); s != "down" {
		t.Errorf("dua kali gagal down: %s", s)
	}
	if s, f := Judge("down", 3, Result{OK: true, MS: 900}, &slow); s != "slow" || f != 0 {
		t.Errorf("pulih tetapi lambat: %s", s)
	}
	if s, _ := Judge("unknown", 0, Result{Error: "x"}, nil); s != "unknown" {
		t.Errorf("cek baru yang gagal sekali masih menunggu: %s", s)
	}
}
