package offsite

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"io"
	"net"
	"strings"
	"testing"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"
)

// sshd uji: menerima password "rahasia", menyediakan subsistem sftp di memori.
func sshd(t *testing.T) (addr string, fp string) {
	t.Helper()
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	signer, err := ssh.NewSignerFromKey(priv)
	if err != nil {
		t.Fatal(err)
	}
	cfg := &ssh.ServerConfig{PasswordCallback: func(c ssh.ConnMetadata, p []byte) (*ssh.Permissions, error) {
		if c.User() == "backup" && string(p) == "rahasia" {
			return nil, nil
		}
		return nil, io.EOF
	}}
	cfg.AddHostKey(signer)
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	handlers := sftp.InMemHandler()
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func() {
				_, chans, reqs, err := ssh.NewServerConn(c, cfg)
				if err != nil {
					return
				}
				go ssh.DiscardRequests(reqs)
				for nc := range chans {
					ch, in, _ := nc.Accept()
					go func() {
						for req := range in {
							ok := req.Type == "subsystem" && string(req.Payload[4:]) == "sftp"
							_ = req.Reply(ok, nil)
							if ok {
								s := sftp.NewRequestServer(ch, handlers)
								_ = s.Serve()
								s.Close()
							}
						}
					}()
				}
			}()
		}
	}()
	return ln.Addr().String(), ssh.FingerprintSHA256(signer.PublicKey())
}

func TestSFTPTujuan(t *testing.T) {
	addr, fp := sshd(t)
	host, port, _ := net.SplitHostPort(addr)
	p := 0
	for _, c := range port {
		p = p*10 + int(c-'0')
	}
	ctx := context.Background()
	var pinned string
	s := &SFTP{Host: host, Port: p, User: "backup", Password: "rahasia", Dir: "/antareja", OnNewHostKey: func(f string) { pinned = f }}
	body := "isi backup"
	if err := s.Put(ctx, "antareja-20261101-013000.dump", strings.NewReader(body), int64(len(body)), ""); err != nil {
		t.Fatal(err)
	}
	if pinned != fp {
		t.Fatalf("host key pertama harus dicatat: %q vs %q", pinned, fp)
	}
	names, err := s.List(ctx)
	if err != nil || len(names) != 1 || names[0] != "antareja-20261101-013000.dump" {
		t.Fatalf("daftar %v %v (berkas .part tidak boleh tertinggal)", names, err)
	}
	if err := s.Delete(ctx, names[0]); err != nil {
		t.Fatal(err)
	}
	s.Close()

	// host key berbeda dari yang tercatat: ditolak
	bad := &SFTP{Host: host, Port: p, User: "backup", Password: "rahasia", HostKey: "SHA256:bukan-ini"}
	if _, err := bad.List(ctx); err == nil || !strings.Contains(err.Error(), "host key server SFTP berubah") {
		t.Fatalf("seharusnya ditolak: %v", err)
	}
	// password salah
	wrong := &SFTP{Host: host, Port: p, User: "backup", Password: "salah", HostKey: fp}
	if _, err := wrong.List(ctx); err == nil {
		t.Fatal("password salah seharusnya gagal")
	}
}
