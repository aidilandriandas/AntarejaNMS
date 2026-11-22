// Package configbak mengambil konfigurasi perangkat lewat SSH untuk disimpan sebagai versi (HANYA BACA).
//
// Perintah dipatok per jenis perangkat dan hanya perintah tampil/ekspor; tidak ada perintah yang mengubah
// konfigurasi, dan pengguna tidak bisa memasukkan perintah sendiri. Host key dicatat saat pertama terhubung
// (trust on first use); bila berubah, backup ditolak sampai engineer mereset dari web.
package configbak

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	"golang.org/x/crypto/ssh"

	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
)

// Commands: perintah baca per jenis perangkat (dijalankan berurutan; keluaran perintah terakhir yang disimpan).
var Commands = map[string][]string{
	"mikrotik": {"/export hide-sensitive"},                   // RouterOS: ekspor konfigurasi tanpa password/kunci
	"cisco":    {"terminal length 0", "show running-config"}, // IOS / IOS-XE
}

var (
	mtHeader      = regexp.MustCompile(`^# \w{3}/\d{2}/\d{4} \d{2}:\d{2}:\d{2} by RouterOS`)
	mtHeaderV7    = regexp.MustCompile(`^# \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} by RouterOS`)
	ciscoVolatile = []*regexp.Regexp{
		regexp.MustCompile(`^Building configuration`),
		regexp.MustCompile(`^Current configuration\s*:`),
		regexp.MustCompile(`^! Last configuration change`),
		regexp.MustCompile(`^! NVRAM config last updated`),
		regexp.MustCompile(`^! No configuration change since last restart`),
		regexp.MustCompile(`^ntp clock-period`),
	}
)

// Normalize membuang baris yang selalu berubah (jam ekspor, penghitung) agar perubahan nyata saja yang terdeteksi.
func Normalize(kind, s string) string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	var out []string
	for _, l := range strings.Split(s, "\n") {
		t := strings.TrimRight(l, " \t\r")
		switch kind {
		case "mikrotik":
			if mtHeader.MatchString(t) || mtHeaderV7.MatchString(t) {
				continue
			}
		case "cisco":
			skip := false
			for _, re := range ciscoVolatile {
				if re.MatchString(t) {
					skip = true
					break
				}
			}
			if skip {
				continue
			}
		}
		out = append(out, t)
	}
	return strings.TrimSpace(strings.Join(out, "\n")) + "\n"
}

// Fingerprint sidik host key gaya OpenSSH (SHA256:...).
func Fingerprint(k ssh.PublicKey) string { return ssh.FingerprintSHA256(k) }

// ErrHostKeyChanged: host key berbeda dari yang tercatat.
var ErrHostKeyChanged = errors.New("host key SSH berubah sejak backup sebelumnya: perangkat diganti/di-reset, atau koneksi disadap. Periksa lalu reset host key dari web")

// Fetch terhubung lewat SSH lalu menjalankan perintah baca. knownKey kosong = catat key baru (dikembalikan).
func Fetch(ctx context.Context, kind, host string, port int, user, pass, knownKey string, timeout time.Duration) (cfg, hostKey string, err error) {
	cmds, ok := Commands[kind]
	if !ok {
		return "", "", fmt.Errorf("jenis perangkat %q tidak didukung", kind)
	}
	cc := &ssh.ClientConfig{
		User: user,
		Auth: []ssh.AuthMethod{ssh.Password(pass), ssh.KeyboardInteractive(func(_, _ string, qs []string, _ []bool) ([]string, error) {
			ans := make([]string, len(qs))
			for i := range qs {
				ans[i] = pass
			}
			return ans, nil
		})},
		HostKeyCallback: func(_ string, _ net.Addr, k ssh.PublicKey) error {
			hostKey = Fingerprint(k)
			if knownKey != "" && knownKey != hostKey {
				return ErrHostKeyChanged
			}
			return nil
		},
		Timeout: timeout,
		// Perangkat jaringan lama sering hanya mendukung algoritma lama; tetap aman untuk koneksi baca.
		Config: ssh.Config{KeyExchanges: []string{"curve25519-sha256", "curve25519-sha256@libssh.org", "ecdh-sha2-nistp256",
			"ecdh-sha2-nistp384", "diffie-hellman-group14-sha256", "diffie-hellman-group14-sha1", "diffie-hellman-group-exchange-sha256"}},
	}
	d := net.Dialer{Timeout: timeout}
	conn, err := d.DialContext(ctx, "tcp", net.JoinHostPort(host, strconv.Itoa(port)))
	if err != nil {
		return "", "", fmt.Errorf("tidak bisa terhubung ke port SSH %d: %w", port, err)
	}
	_ = conn.SetDeadline(time.Now().Add(timeout * 4))
	sc, chans, reqs, err := ssh.NewClientConn(conn, host, cc)
	if err != nil {
		conn.Close()
		if errors.Is(err, ErrHostKeyChanged) || strings.Contains(err.Error(), ErrHostKeyChanged.Error()) {
			return "", hostKey, ErrHostKeyChanged
		}
		if strings.Contains(err.Error(), "unable to authenticate") {
			return "", hostKey, errors.New("login SSH ditolak (user/password salah)")
		}
		return "", hostKey, fmt.Errorf("SSH gagal: %w", err)
	}
	client := ssh.NewClient(sc, chans, reqs)
	defer client.Close()
	for i, c := range cmds {
		s, err := client.NewSession()
		if err != nil {
			return "", hostKey, fmt.Errorf("sesi SSH gagal: %w", err)
		}
		var out, errb bytes.Buffer
		s.Stdout, s.Stderr = &out, &errb
		err = s.Run(c)
		s.Close()
		if i == len(cmds)-1 {
			if err != nil && out.Len() == 0 {
				return "", hostKey, fmt.Errorf("perintah %q gagal: %v %s", c, err, strings.TrimSpace(errb.String()))
			}
			if out.Len() < 20 {
				return "", hostKey, fmt.Errorf("keluaran %q kosong, periksa hak akses user SSH", c)
			}
			return out.String(), hostKey, nil
		}
	}
	return "", hostKey, errors.New("tidak ada perintah")
}

// Runner menjalankan backup untuk perangkat yang sudah waktunya (24 jam) atau diminta dari web.
type Runner struct {
	DB      *pgxpool.Pool
	Box     *secret.Box
	Log     *slog.Logger
	Every   time.Duration
	Timeout time.Duration
	Keep    int
}

type target struct {
	ID                     int64
	Name, Host, Kind, User string
	Port                   int
	PassEnc, HostKey       *string
}

// Run memproses perangkat yang jatuh tempo; dipanggil berkala (mis. tiap menit untuk menangkap permintaan manual).
func (r *Runner) Run(ctx context.Context) error {
	rows, err := r.DB.Query(ctx, `SELECT id, name, host, cfg_type, COALESCE(ssh_user, ''), ssh_port, ssh_password_enc, ssh_hostkey FROM devices
		WHERE enabled AND cfg_type IS NOT NULL AND (cfg_requested OR cfg_last_at IS NULL OR cfg_last_at < now() - make_interval(secs => $1))
		ORDER BY cfg_requested DESC, cfg_last_at NULLS FIRST LIMIT 20`, r.Every.Seconds())
	if err != nil {
		return err
	}
	var ts []target
	for rows.Next() {
		var t target
		if err := rows.Scan(&t.ID, &t.Name, &t.Host, &t.Kind, &t.User, &t.Port, &t.PassEnc, &t.HostKey); err != nil {
			rows.Close()
			return err
		}
		ts = append(ts, t)
	}
	rows.Close()
	for _, t := range ts {
		if ctx.Err() != nil {
			break
		}
		r.one(ctx, t)
	}
	return nil
}

func (r *Runner) fail(ctx context.Context, t target, msg string) {
	if len(msg) > 250 {
		msg = msg[:250]
	}
	_, _ = r.DB.Exec(ctx, `UPDATE devices SET cfg_last_at = now(), cfg_error = $2, cfg_requested = false WHERE id = $1`, t.ID, msg)
	r.Log.Warn("backup konfigurasi gagal", "perangkat", t.ID, "galat", msg)
}

func (r *Runner) one(ctx context.Context, t target) {
	if t.User == "" || t.PassEnc == nil {
		r.fail(ctx, t, "user/password SSH belum diisi")
		return
	}
	pass, err := r.Box.Decrypt(*t.PassEnc)
	if err != nil {
		r.fail(ctx, t, "password SSH tidak bisa dibuka (ANTAREJA_APP_KEY berbeda?)")
		return
	}
	known := ""
	if t.HostKey != nil {
		known = *t.HostKey
	}
	raw, hostKey, err := Fetch(ctx, t.Kind, t.Host, t.Port, t.User, pass, known, r.Timeout)
	if err != nil {
		r.fail(ctx, t, err.Error())
		return
	}
	cfg := Normalize(t.Kind, raw)
	sum := sha256.Sum256([]byte(cfg))
	hash := hex.EncodeToString(sum[:])

	var prevHash, prevEnc *string
	_ = r.DB.QueryRow(ctx, `SELECT sha256, content_enc FROM device_configs WHERE device_id = $1 ORDER BY taken_at DESC LIMIT 1`, t.ID).Scan(&prevHash, &prevEnc)
	_, _ = r.DB.Exec(ctx, `UPDATE devices SET cfg_last_at = now(), cfg_error = NULL, cfg_requested = false, ssh_hostkey = COALESCE(ssh_hostkey, $2) WHERE id = $1`, t.ID, hostKey)
	if prevHash != nil && *prevHash == hash {
		return // tidak berubah: cukup catat waktu pemeriksaan
	}
	var diffText string
	var added, removed int
	lines := strings.Split(strings.TrimRight(cfg, "\n"), "\n")
	if prevEnc != nil {
		if prev, err := r.Box.Decrypt(*prevEnc); err == nil {
			diffText, added, removed = Diff(strings.Split(strings.TrimRight(prev, "\n"), "\n"), lines)
		}
	}
	enc, err := r.Box.Encrypt(cfg)
	if err != nil {
		r.fail(ctx, t, "enkripsi gagal")
		return
	}
	var diffEnc *string
	if diffText != "" {
		e, err := r.Box.Encrypt(diffText)
		if err == nil {
			diffEnc = &e
		}
	}
	if _, err := r.DB.Exec(ctx, `INSERT INTO device_configs (device_id, sha256, lines, size_bytes, content_enc, diff_enc, added, removed) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)`,
		t.ID, hash, len(lines), len(cfg), enc, diffEnc, added, removed); err != nil {
		r.fail(ctx, t, "simpan versi gagal: "+err.Error())
		return
	}
	if prevHash != nil {
		msg := fmt.Sprintf("Konfigurasi %s berubah (+%d / −%d baris)", t.Name, added, removed)
		_, _ = r.DB.Exec(ctx, `INSERT INTO events (device_id, kind, message) VALUES ($1, 'config_change', $2)`, t.ID, msg)
		r.Log.Info("konfigurasi berubah", "perangkat", t.ID, "tambah", added, "hapus", removed)
	} else {
		r.Log.Info("backup konfigurasi pertama", "perangkat", t.ID, "baris", len(lines))
	}
	// Retensi: simpan N versi terbaru
	_, _ = r.DB.Exec(ctx, `DELETE FROM device_configs WHERE device_id = $1 AND id NOT IN (SELECT id FROM device_configs WHERE device_id = $1 ORDER BY taken_at DESC LIMIT $2)`, t.ID, r.Keep)
}
