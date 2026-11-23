package offsite

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"path"
	"strconv"
	"time"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"
)

// SFTP tujuan server SFTP (NAS, server lain). Host key dikunci: bila HostKey kosong, sidik jari server pertama dicatat
// lewat OnNewHostKey; berikutnya harus sama persis.
type SFTP struct {
	Host, User, Dir string
	Port            int
	Password        string
	PrivateKey      string
	HostKey         string // SHA256:... dari koneksi sebelumnya
	OnNewHostKey    func(fp string)
	SeenHostKey     string // diisi saat koneksi (untuk ditampilkan saat uji)

	ssh *ssh.Client
	c   *sftp.Client
}

func (s *SFTP) connect(ctx context.Context) error {
	if s.c != nil {
		return nil
	}
	var auth []ssh.AuthMethod
	if s.PrivateKey != "" {
		var signer ssh.Signer
		var err error
		if s.Password != "" {
			signer, err = ssh.ParsePrivateKeyWithPassphrase([]byte(s.PrivateKey), []byte(s.Password))
		} else {
			signer, err = ssh.ParsePrivateKey([]byte(s.PrivateKey))
		}
		if err != nil {
			return fmt.Errorf("private key tidak bisa dibaca: %w", err)
		}
		auth = append(auth, ssh.PublicKeys(signer))
	} else if s.Password != "" {
		auth = append(auth, ssh.Password(s.Password))
	}
	if len(auth) == 0 {
		return errors.New("isi password atau private key SFTP")
	}
	port := s.Port
	if port == 0 {
		port = 22
	}
	cfg := &ssh.ClientConfig{User: s.User, Auth: auth, Timeout: 15 * time.Second,
		HostKeyCallback: func(_ string, _ net.Addr, key ssh.PublicKey) error {
			fp := ssh.FingerprintSHA256(key)
			s.SeenHostKey = fp
			if s.HostKey == "" {
				if s.OnNewHostKey != nil {
					s.OnNewHostKey(fp)
				}
				return nil
			}
			if fp != s.HostKey {
				return fmt.Errorf("host key server SFTP berubah (tercatat %s, sekarang %s); bila server memang diganti, reset host key di halaman Backup", s.HostKey, fp)
			}
			return nil
		}}
	d := net.Dialer{Timeout: 15 * time.Second}
	conn, err := d.DialContext(ctx, "tcp", net.JoinHostPort(s.Host, strconv.Itoa(port)))
	if err != nil {
		return err
	}
	c, chans, reqs, err := ssh.NewClientConn(conn, s.Host, cfg)
	if err != nil {
		conn.Close()
		return err
	}
	s.ssh = ssh.NewClient(c, chans, reqs)
	s.c, err = sftp.NewClient(s.ssh)
	if err != nil {
		s.ssh.Close()
		return fmt.Errorf("server tidak menyediakan SFTP: %w", err)
	}
	if s.Dir != "" {
		if err := s.c.MkdirAll(s.Dir); err != nil {
			return fmt.Errorf("folder tujuan %s tidak bisa dibuat: %w", s.Dir, err)
		}
	}
	return nil
}

func (s *SFTP) p(name string) string {
	if s.Dir == "" {
		return name
	}
	return path.Join(s.Dir, name)
}

// Put menulis ke berkas sementara lalu mengganti nama, sehingga berkas setengah jadi tidak pernah tampak lengkap.
func (s *SFTP) Put(ctx context.Context, name string, r io.Reader, size int64, _ string) error {
	if err := s.connect(ctx); err != nil {
		return err
	}
	tmp := s.p("." + name + ".part")
	f, err := s.c.Create(tmp)
	if err != nil {
		return err
	}
	n, err := io.Copy(f, r)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = s.c.Remove(tmp)
		return err
	}
	if n != size {
		_ = s.c.Remove(tmp)
		return fmt.Errorf("terkirim %d dari %d byte", n, size)
	}
	_ = s.c.Remove(s.p(name))
	if err := s.c.Rename(tmp, s.p(name)); err != nil {
		return err
	}
	st, err := s.c.Stat(s.p(name))
	if err != nil {
		return err
	}
	if st.Size() != size {
		return fmt.Errorf("ukuran di server %d, seharusnya %d", st.Size(), size)
	}
	return nil
}

func (s *SFTP) List(ctx context.Context) ([]string, error) {
	if err := s.connect(ctx); err != nil {
		return nil, err
	}
	dir := s.Dir
	if dir == "" {
		dir = "."
	}
	fis, err := s.c.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	out := make([]string, 0, len(fis))
	for _, fi := range fis {
		if !fi.IsDir() {
			out = append(out, fi.Name())
		}
	}
	return out, nil
}

func (s *SFTP) Delete(ctx context.Context, name string) error {
	if err := s.connect(ctx); err != nil {
		return err
	}
	return s.c.Remove(s.p(name))
}

func (s *SFTP) Close() error {
	if s.c != nil {
		s.c.Close()
	}
	if s.ssh != nil {
		return s.ssh.Close()
	}
	return nil
}

func (s *SFTP) Describe() string { return "sftp " + s.User + "@" + s.Host + ":" + s.Dir }
