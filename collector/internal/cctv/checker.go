package cctv

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
)

// Checker mengambil satu gambar per kamera (sub-stream) lewat go2rtc lalu menilai kesehatannya.
type Checker struct {
	DB        *pgxpool.Pool
	Box       *secret.Box
	Go2rtcURL string
	Log       *slog.Logger
	HTTP      *http.Client

	mu    sync.Mutex
	prev  map[int64]uint64
	same  map[int64]int
	fails map[int64]int
}

type camera struct {
	ID                        int64
	Name, Host, PathSub, User string
	Port, Channel             int
	PassEnc                   *string
}

func (c *Checker) client() *http.Client {
	if c.HTTP != nil {
		return c.HTTP
	}
	return &http.Client{Timeout: 30 * time.Second}
}

// Run memeriksa semua kamera aktif (4 sekaligus).
func (c *Checker) Run(ctx context.Context) error {
	if c.Go2rtcURL == "" {
		return nil
	}
	c.mu.Lock()
	if c.prev == nil {
		c.prev, c.same, c.fails = map[int64]uint64{}, map[int64]int{}, map[int64]int{}
	}
	c.mu.Unlock()
	rows, err := c.DB.Query(ctx, `SELECT id, name, host, rtsp_port, channel, path_sub, COALESCE(username, ''), password_enc FROM cameras WHERE enabled`)
	if err != nil {
		return err
	}
	var cams []camera
	for rows.Next() {
		var k camera
		if err := rows.Scan(&k.ID, &k.Name, &k.Host, &k.Port, &k.Channel, &k.PathSub, &k.User, &k.PassEnc); err != nil {
			rows.Close()
			return err
		}
		cams = append(cams, k)
	}
	rows.Close()

	jobs := make(chan camera)
	var wg sync.WaitGroup
	var bad int
	var mu sync.Mutex
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for k := range jobs {
				if h := c.check(ctx, k); h != "ok" && h != "" {
					mu.Lock()
					bad++
					mu.Unlock()
				}
			}
		}()
	}
	for _, k := range cams {
		jobs <- k
	}
	close(jobs)
	wg.Wait()
	if len(cams) > 0 {
		c.Log.Info("cek gambar CCTV", "kamera", len(cams), "bermasalah", bad)
	}
	return nil
}

func (c *Checker) rtsp(k camera) (string, error) {
	auth := ""
	if k.User != "" {
		pass := ""
		if k.PassEnc != nil && *k.PassEnc != "" {
			p, err := c.Box.Decrypt(*k.PassEnc)
			if err != nil {
				return "", fmt.Errorf("password kamera tidak bisa dibuka: %w", err)
			}
			pass = p
		}
		auth = url.UserPassword(k.User, pass).String() + "@"
	}
	path := strings.ReplaceAll(k.PathSub, "{ch}", strconv.Itoa(k.Channel))
	return "rtsp://" + auth + k.Host + ":" + strconv.Itoa(k.Port) + "/" + strings.TrimLeft(path, "/"), nil
}

func (c *Checker) check(ctx context.Context, k camera) string {
	name := fmt.Sprintf("cam%d_sub", k.ID)
	base := strings.TrimRight(c.Go2rtcURL, "/")
	src, err := c.rtsp(k)
	var health, detail string
	var res Result
	if err == nil {
		// Daftarkan aliran (go2rtc bisa menjawab 400 karena konfigurasinya read-only; aliran tetap aktif)
		req, _ := http.NewRequestWithContext(ctx, http.MethodPut, base+"/api/streams?"+url.Values{"name": {name}, "src": {src}}.Encode(), nil)
		if resp, e := c.client().Do(req); e == nil {
			resp.Body.Close()
		}
		var jpg []byte
		jpg, err = c.frame(ctx, base, name)
		if err == nil {
			res, err = Analyze(jpg)
		}
	}

	c.mu.Lock()
	if err != nil {
		c.fails[k.ID]++
		fails := c.fails[k.ID]
		c.mu.Unlock()
		if fails < 2 {
			return "" // satu kali gagal: tunggu pemeriksaan berikutnya sebelum menyatakan tanpa sinyal
		}
		health, detail = "nosignal", friendly(err)
	} else {
		c.fails[k.ID] = 0
		if p, ok := c.prev[k.ID]; ok && p == res.Hash {
			c.same[k.ID]++
		} else {
			c.same[k.ID] = 0
		}
		c.prev[k.ID] = res.Hash
		same := c.same[k.ID]
		c.mu.Unlock()
		health, detail = Judge(res, same)
	}

	var bright, det *float64
	if err == nil {
		bright, det = &res.Brightness, &res.Detail
	}
	if _, e := c.DB.Exec(ctx, `UPDATE cameras SET health_since = CASE WHEN health IS DISTINCT FROM $2 THEN now() ELSE health_since END,
		health = $2, health_detail = NULLIF($3, ''), health_at = now(), brightness = $4, detail_score = $5 WHERE id = $1`,
		k.ID, health, detail, bright, det); e != nil {
		c.Log.Warn("simpan kesehatan kamera gagal", "kamera", k.ID, "galat", e)
	}
	return health
}

func (c *Checker) frame(ctx context.Context, base, name string) ([]byte, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, base+"/api/frame.jpeg?src="+url.QueryEscape(name), nil)
	resp, err := c.client().Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if resp.StatusCode != http.StatusOK || len(body) < 100 {
		return nil, fmt.Errorf("HTTP %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	return body, nil
}

func friendly(err error) string {
	m := err.Error()
	switch {
	case strings.Contains(m, "wrong user/pass") || strings.Contains(m, "401"):
		return "login RTSP ditolak (user/password salah)"
	case strings.Contains(m, "timeout") || strings.Contains(m, "i/o") || strings.Contains(m, "deadline"):
		return "kamera tidak menjawab (cek IP, port 554, jaringan)"
	case strings.Contains(m, "connection refused"):
		return "port RTSP ditolak"
	case strings.Contains(m, "404") || strings.Contains(m, "not found"):
		return "path stream tidak ditemukan (cek nomor kanal / path)"
	}
	if strings.HasPrefix(m, "HTTP ") && len(strings.TrimSpace(m)) <= 8 {
		// go2rtc tidak menyertakan alasan
		return "tidak ada gambar dari kamera, cek user/password RTSP, IP, dan nomor kanal"
	}
	if len(m) > 150 {
		m = m[:150]
	}
	return "tidak ada gambar: " + m
}
