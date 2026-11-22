package configbak

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/binary"
	"net"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"
)

// fakeMikrotik: server SSH palsu yang hanya menjawab "/export hide-sensitive" dan mencatat semua perintah.
func fakeMikrotik(t *testing.T, pass string) (addr string, port int, cmds *[]string, stop func()) {
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	signer, _ := ssh.NewSignerFromKey(priv)
	cfg := &ssh.ServerConfig{PasswordCallback: func(c ssh.ConnMetadata, p []byte) (*ssh.Permissions, error) {
		if c.User() == "backup" && string(p) == pass {
			return nil, nil
		}
		return nil, ssh.ErrNoAuth
	}}
	cfg.AddHostKey(signer)
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	seen := []string{}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func(c net.Conn) {
				_, chans, reqs, err := ssh.NewServerConn(c, cfg)
				if err != nil {
					return
				}
				go ssh.DiscardRequests(reqs)
				for nc := range chans {
					ch, rq, _ := nc.Accept()
					go func() {
						for r := range rq {
							if r.Type != "exec" {
								r.Reply(false, nil)
								continue
							}
							cmd := string(r.Payload[4:])
							mu.Lock()
							seen = append(seen, cmd)
							mu.Unlock()
							r.Reply(true, nil)
							if cmd == "/export hide-sensitive" {
								ch.Write([]byte("# nov/22/2026 07:00:0" + strconv.Itoa(len(seen)%10) + " by RouterOS 7.15.3\n/interface bridge\nadd name=bridge1\n/ip address\nadd address=10.10.1.1/24 interface=bridge1\n"))
							}
							status := make([]byte, 4)
							binary.BigEndian.PutUint32(status, 0)
							ch.SendRequest("exit-status", false, status)
							ch.Close()
						}
					}()
				}
			}(c)
		}
	}()
	p := ln.Addr().(*net.TCPAddr).Port
	return "127.0.0.1", p, &seen, func() { ln.Close() }
}

func TestFetch(t *testing.T) {
	host, port, cmds, stop := fakeMikrotik(t, "Rahasia1")
	defer stop()
	ctx := context.Background()
	cfg, key, err := Fetch(ctx, "mikrotik", host, port, "backup", "Rahasia1", "", 5*time.Second)
	if err != nil || !strings.Contains(cfg, "add name=bridge1") || !strings.HasPrefix(key, "SHA256:") {
		t.Fatalf("cfg=%q key=%q err=%v", cfg, key, err)
	}
	// Normalisasi: dua ekspor dengan jam berbeda menghasilkan isi sama
	cfg2, _, _ := Fetch(ctx, "mikrotik", host, port, "backup", "Rahasia1", key, 5*time.Second)
	if Normalize("mikrotik", cfg) != Normalize("mikrotik", cfg2) || cfg == cfg2 {
		t.Fatal("baris jam ekspor harus dibuang agar tidak dianggap perubahan")
	}
	if _, _, err := Fetch(ctx, "mikrotik", host, port, "backup", "salah", key, 5*time.Second); err == nil || !strings.Contains(err.Error(), "login SSH ditolak") {
		t.Fatalf("password salah: %v", err)
	}
	if _, _, err := Fetch(ctx, "mikrotik", host, port, "backup", "Rahasia1", "SHA256:lain", 5*time.Second); err != ErrHostKeyChanged {
		t.Fatalf("host key berubah harus ditolak: %v", err)
	}
	for _, c := range *cmds {
		if c != "/export hide-sensitive" {
			t.Fatalf("perintah selain ekspor terkirim: %q", c)
		}
	}
}
