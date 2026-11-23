// Package offsite menyalin berkas backup NMS ke luar server (SFTP atau S3/MinIO) dan menjaga retensi di sana.
package offsite

import (
	"context"
	"crypto/md5"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
)

// Target tujuan salinan.
type Target interface {
	Put(ctx context.Context, name string, r io.Reader, size int64, md5b64 string) error
	List(ctx context.Context) ([]string, error)
	Delete(ctx context.Context, name string) error
	Close() error
	Describe() string
}

// Config setelan tujuan (settings 'backup.offsite'); rahasia terpisah dan terenkripsi.
type Config struct {
	Type string `json:"type"` // off | sftp | s3
	Keep int    `json:"keep"`
	// SFTP
	Host string `json:"host"`
	Port int    `json:"port"`
	User string `json:"user"`
	Dir  string `json:"dir"`
	// S3
	Endpoint  string `json:"endpoint"`
	Region    string `json:"region"`
	Bucket    string `json:"bucket"`
	Prefix    string `json:"prefix"`
	AccessKey string `json:"access_key"`
}

type secrets struct {
	Password   string `json:"password"`
	PrivateKey string `json:"private_key"`
	SecretKey  string `json:"secret_key"`
}

const maxTries = 6

// errPermanent: kegagalan yang tidak akan sembuh dengan mencoba lagi (berkas sudah tidak ada, nama tidak wajar).
var errPermanent = errors.New("permanen")

// Runner menyalin backup yang selesai dan belum tersalin.
type Runner struct {
	DB  *pgxpool.Pool
	Box *secret.Box
	Log *slog.Logger
	Dir string // folder backup yang di-mount ke collector (bawaan /backups)
}

func (r *Runner) load(ctx context.Context) (Config, Target, error) {
	var c Config
	var raw []byte
	if err := r.DB.QueryRow(ctx, `SELECT value::text FROM settings WHERE key = 'backup.offsite'`).Scan(&raw); err != nil {
		return c, nil, nil
	}
	if err := json.Unmarshal(raw, &c); err != nil || c.Type == "" || c.Type == "off" {
		return c, nil, nil
	}
	if c.Keep <= 0 {
		c.Keep = 30
	}
	var sec secrets
	var enc string
	if err := r.DB.QueryRow(ctx, `SELECT value #>> '{}' FROM settings WHERE key = 'backup.offsite_secret'`).Scan(&enc); err == nil && enc != "" {
		plain, err := r.Box.Decrypt(enc)
		if err != nil {
			return c, nil, errors.New("rahasia tujuan backup tidak bisa dibuka (kunci aplikasi berubah?)")
		}
		_ = json.Unmarshal([]byte(plain), &sec)
	}
	switch c.Type {
	case "sftp":
		var hk string
		_ = r.DB.QueryRow(ctx, `SELECT value #>> '{}' FROM settings WHERE key = 'backup.offsite_hostkey'`).Scan(&hk)
		return c, &SFTP{Host: c.Host, Port: c.Port, User: c.User, Dir: c.Dir, Password: sec.Password, PrivateKey: sec.PrivateKey, HostKey: hk,
			OnNewHostKey: func(fp string) {
				_, _ = r.DB.Exec(context.Background(), `INSERT INTO settings (key, value) VALUES ('backup.offsite_hostkey', to_jsonb($1::text))
					ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`, fp)
				r.Log.Info("host key SFTP tujuan backup dicatat", "sidik_jari", fp)
			}}, nil
	case "s3":
		p := strings.TrimLeft(c.Prefix, "/")
		if p != "" && !strings.HasSuffix(p, "/") {
			p += "/"
		}
		region := c.Region
		if region == "" {
			region = "us-east-1"
		}
		return c, &S3{Endpoint: c.Endpoint, Region: region, Bucket: c.Bucket, Prefix: p, AccessKey: c.AccessKey, SecretKey: sec.SecretKey}, nil
	}
	return c, nil, fmt.Errorf("jenis tujuan tidak dikenal: %s", c.Type)
}

func (r *Runner) dir() string {
	if r.Dir != "" {
		return r.Dir
	}
	return "/backups"
}

// Run satu putaran: unggah backup yang selesai dan belum tersalin (terbaru dulu, maks. 3), lalu rapikan retensi.
func (r *Runner) Run(ctx context.Context) error {
	c, t, err := r.load(ctx)
	if err != nil || t == nil {
		return err
	}
	defer t.Close()
	// backup lama yang sudah ada sebelum fitur diaktifkan: hanya yang masih ada berkasnya, maks. sebanyak retensi
	rows, err := r.DB.Query(ctx, `SELECT id, file FROM backups
		WHERE status = 'ok' AND file IS NOT NULL
		  AND (offsite_status IS NULL OR offsite_status = 'pending'
		       OR (offsite_status = 'failed' AND offsite_tries < $1 AND offsite_at < now() - make_interval(mins => 10 * offsite_tries)))
		ORDER BY id DESC LIMIT 3`, maxTries)
	if err != nil {
		return err
	}
	type job struct {
		id   int64
		file string
	}
	var jobs []job
	for rows.Next() {
		var j job
		if rows.Scan(&j.id, &j.file) == nil {
			jobs = append(jobs, j)
		}
	}
	rows.Close()
	uploaded := 0
	for _, j := range jobs {
		start := time.Now()
		err := r.copyOne(ctx, t, j.file)
		if err != nil {
			msg := err.Error()
			if len(msg) > 480 {
				msg = msg[:480]
			}
			tries := "offsite_tries + 1"
			if errors.Is(err, errPermanent) {
				tries = "6" // tidak dicoba lagi
			}
			_, _ = r.DB.Exec(ctx, `UPDATE backups SET offsite_status = 'failed', offsite_at = now(), offsite_error = $2, offsite_tries = `+tries+` WHERE id = $1`, j.id, msg)
			r.Log.Warn("salinan backup ke luar server gagal", "berkas", j.file, "tujuan", t.Describe(), "galat", msg)
			continue
		}
		_, _ = r.DB.Exec(ctx, `UPDATE backups SET offsite_status = 'ok', offsite_at = now(), offsite_error = NULL WHERE id = $1`, j.id)
		r.Log.Info("backup tersalin ke luar server", "berkas", j.file, "tujuan", t.Describe(), "durasi", time.Since(start).Round(time.Second))
		uploaded++
	}
	if uploaded > 0 {
		if err := prune(ctx, t, c.Keep); err != nil {
			r.Log.Warn("retensi salinan backup gagal", "galat", err)
		}
	}
	return nil
}

func (r *Runner) copyOne(ctx context.Context, t Target, file string) error {
	if strings.ContainsAny(file, "/\\") || !strings.HasPrefix(file, "antareja-") {
		return fmt.Errorf("%w: nama berkas tidak wajar: %s", errPermanent, file)
	}
	for _, name := range []string{file, strings.TrimSuffix(file, ".dump") + ".json"} {
		p := filepath.Join(r.dir(), name)
		f, err := os.Open(p)
		if err != nil {
			if name != file && errors.Is(err, os.ErrNotExist) {
				continue // manifest opsional
			}
			if errors.Is(err, os.ErrNotExist) {
				return fmt.Errorf("%w: berkas sudah tidak ada di folder backup (terhapus retensi lokal)", errPermanent)
			}
			return fmt.Errorf("berkas backup tidak terbaca di %s: %w", r.dir(), err)
		}
		st, _ := f.Stat()
		h := md5.New()
		if _, err := io.Copy(h, f); err != nil {
			f.Close()
			return err
		}
		if _, err := f.Seek(0, io.SeekStart); err != nil {
			f.Close()
			return err
		}
		err = t.Put(ctx, name, f, st.Size(), base64.StdEncoding.EncodeToString(h.Sum(nil)))
		f.Close()
		if err != nil {
			return err
		}
	}
	return nil
}

// prune menyimpan keep backup terbaru di tujuan (nama antareja-*.dump berurutan waktu), sisanya dihapus beserta manifest.
func prune(ctx context.Context, t Target, keep int) error {
	names, err := t.List(ctx)
	if err != nil {
		return err
	}
	var dumps []string
	for _, n := range names {
		if strings.HasPrefix(n, "antareja-") && strings.HasSuffix(n, ".dump") {
			dumps = append(dumps, n)
		}
	}
	sort.Sort(sort.Reverse(sort.StringSlice(dumps)))
	for i, n := range dumps {
		if i < keep {
			continue
		}
		if err := t.Delete(ctx, n); err != nil {
			return err
		}
		_ = t.Delete(ctx, strings.TrimSuffix(n, ".dump")+".json")
	}
	return nil
}

// TestResult hasil uji koneksi dari web.
type TestResult struct {
	OK      bool   `json:"ok"`
	Target  string `json:"target"`
	Detail  string `json:"detail"`
	HostKey string `json:"hostkey,omitempty"`
}

// Test: tulis berkas kecil, pastikan tampil di daftar, lalu hapus.
func (r *Runner) Test(ctx context.Context) TestResult {
	_, t, err := r.load(ctx)
	if err != nil {
		return TestResult{Detail: err.Error()}
	}
	if t == nil {
		return TestResult{Detail: "salinan ke luar server belum diaktifkan"}
	}
	defer t.Close()
	res := TestResult{Target: t.Describe()}
	name := "antareja-uji-" + time.Now().Format("20060102-150405") + ".txt"
	body := []byte("Uji tulis dari ANTAREJA NMS. Berkas ini dihapus otomatis.\n")
	sum := md5.Sum(body)
	err = t.Put(ctx, name, strings.NewReader(string(body)), int64(len(body)), base64.StdEncoding.EncodeToString(sum[:]))
	if s, ok := t.(*SFTP); ok {
		res.HostKey = s.SeenHostKey
	}
	if err != nil {
		res.Detail = "tidak bisa menulis: " + err.Error()
		return res
	}
	names, err := t.List(ctx)
	if err != nil {
		res.Detail = "tertulis, tetapi daftar berkas tidak bisa dibaca: " + err.Error()
		return res
	}
	found := false
	for _, n := range names {
		found = found || n == name
	}
	if err := t.Delete(ctx, name); err != nil {
		res.Detail = "tertulis, tetapi tidak bisa menghapus (retensi tidak akan berjalan): " + err.Error()
		return res
	}
	if !found {
		res.Detail = "tertulis, tetapi tidak muncul di daftar berkas tujuan"
		return res
	}
	res.OK = true
	res.Detail = fmt.Sprintf("bisa menulis, membaca daftar, dan menghapus di %s (%d berkas lain di sana)", t.Describe(), len(names)-1)
	return res
}
