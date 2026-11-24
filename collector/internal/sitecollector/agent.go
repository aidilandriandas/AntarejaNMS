package sitecollector

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/checks"
	"github.com/aidilandriandas/antarejanms/collector/internal/config"
	"github.com/aidilandriandas/antarejanms/collector/internal/modbus"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/promx"
)

// Agent berjalan di site: mengambil daftar perangkat dari pusat, mengukur, mengantrekan, dan mengirim.
type Agent struct {
	Cfg     config.Config
	Log     *slog.Logger
	Version string
	P       *poller.Poller // hanya klien ping & SNMP yang dipakai; tidak ada database di site

	HTTP  *http.Client
	Spool *Spool

	mu        sync.RWMutex
	devices   []DeviceConfig
	checks    []CheckConfig
	modbus    []ModbusConfig
	exporters []ExporterConfig
	pxState   promx.States
	lastChk   map[int64]time.Time // waktu terakhir tiap cek dijalankan
	name      string
	prof      poller.ProfileCache

	lastPing  atomic.Int64
	lastSent  atomic.Int64
	lastError atomic.Value // string
}

const (
	configEvery = 5 * time.Minute
	snmpChunk   = 40 // sampel SNMP per berkas antrean
)

// NewAgent menyiapkan klien HTTPS (CA internal opsional) dan antrean.
func NewAgent(cfg config.Config, p *poller.Poller, version string, log *slog.Logger) (*Agent, error) {
	tlsCfg := &tls.Config{MinVersion: tls.VersionTLS12}
	if cfg.CentralCA != "" {
		pem, err := os.ReadFile(cfg.CentralCA)
		if err != nil {
			return nil, fmt.Errorf("berkas CA pusat tidak terbaca: %w", err)
		}
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, errors.New("berkas CA pusat bukan sertifikat PEM")
		}
		tlsCfg.RootCAs = pool
	}
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.TLSClientConfig = tlsCfg
	return &Agent{Cfg: cfg, Log: log, Version: version, P: p,
		HTTP:  &http.Client{Transport: tr, Timeout: 60 * time.Second},
		Spool: &Spool{Dir: cfg.SpoolDir, MaxBytes: int64(cfg.SpoolMaxMB) << 20}}, nil
}

// Run menjalankan semua putaran sampai ctx dibatalkan.
func (a *Agent) Run(ctx context.Context) error {
	if err := a.Spool.init(); err != nil {
		return fmt.Errorf("folder antrean %s tidak bisa dibuat: %w", a.Spool.Dir, err)
	}
	a.loadCache()
	var wg sync.WaitGroup
	every := func(d time.Duration, fn func(context.Context)) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			t := time.NewTicker(d)
			defer t.Stop()
			for {
				cctx, cancel := context.WithTimeout(ctx, d)
				fn(cctx)
				cancel()
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()
	}
	// konfigurasi diambil tiap 5 menit; setelah gagal dicoba lagi tiap 30 detik sampai berhasil
	var lastOK time.Time
	every(30*time.Second, func(c context.Context) {
		if time.Since(lastOK) < configEvery {
			return
		}
		if err := a.fetchConfig(c); err != nil {
			a.Log.Warn("daftar perangkat dari pusat belum bisa diambil, memakai salinan terakhir; dicoba lagi 30 detik", "galat", err)
			return
		}
		lastOK = time.Now()
	})
	every(a.Cfg.PingInterval, a.pingCycle)
	every(a.Cfg.SNMPInterval, a.snmpCycle)
	every(15*time.Second, a.checkCycle)
	every(time.Minute, a.modbusCycle)
	every(time.Minute, a.exporterCycle)
	wg.Add(1)
	go func() { defer wg.Done(); a.sendLoop(ctx) }()
	wg.Wait()
	return nil
}

// Healthy: putaran ping berjalan (pengiriman boleh tertunda, karena itu memang tugas antrean).
func (a *Agent) Healthy() (bool, string) {
	last := a.lastPing.Load()
	if last == 0 {
		return false, "putaran ping pertama belum selesai"
	}
	age := time.Since(time.Unix(last, 0))
	q := len(a.Spool.Files())
	msg := fmt.Sprintf("ping %s lalu, antrean %d berkas", age.Round(time.Second), q)
	if s := a.lastSent.Load(); s > 0 {
		msg += fmt.Sprintf(", terkirim ke pusat %s lalu", time.Since(time.Unix(s, 0)).Round(time.Second))
	}
	if e, _ := a.lastError.Load().(string); e != "" {
		msg += ", galat terakhir: " + e
	}
	return age <= 3*a.Cfg.PingInterval, msg
}

func (a *Agent) snapshot() []DeviceConfig {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.devices
}

func (a *Agent) cachePath() string { return filepath.Join(a.Spool.Dir, "devices.json") }

// loadCache membaca salinan daftar perangkat (tanpa kredensial) supaya ping tetap jalan setelah restart
// walau jalur ke pusat sedang putus.
func (a *Agent) loadCache() {
	raw, err := os.ReadFile(a.cachePath())
	if err != nil {
		return
	}
	var devs []DeviceConfig
	if json.Unmarshal(raw, &devs) == nil {
		a.mu.Lock()
		a.devices = devs
		a.mu.Unlock()
		a.Log.Info("salinan daftar perangkat dimuat", "perangkat", len(devs))
	}
}

func (a *Agent) request(ctx context.Context, method, path string, body []byte, hdr map[string]string) (*http.Response, error) {
	req, err := http.NewRequestWithContext(ctx, method, a.Cfg.CentralURL+path, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+a.Cfg.SiteToken)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "antareja-site-collector/"+a.Version)
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	return a.HTTP.Do(req)
}

func (a *Agent) fetchConfig(ctx context.Context) error {
	resp, err := a.request(ctx, http.MethodGet, "/api/site/v1/config", nil, nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("pusat menjawab %s%s", resp.Status, hint(resp.StatusCode))
	}
	var c Config
	if err := json.NewDecoder(io.LimitReader(resp.Body, 32<<20)).Decode(&c); err != nil {
		return fmt.Errorf("jawaban pusat tidak terbaca: %w", err)
	}
	a.mu.Lock()
	changed := a.name != c.Collector.Name || len(a.devices) != len(c.Devices)
	a.devices, a.name, a.checks, a.modbus, a.exporters = c.Devices, c.Collector.Name, c.Checks, c.Modbus, c.Exporters
	a.mu.Unlock()
	if raw, err := json.Marshal(withoutSecrets(c.Devices)); err == nil {
		_ = os.WriteFile(a.cachePath(), raw, 0o600)
	}
	if changed {
		a.Log.Info("daftar perangkat dari pusat", "collector", c.Collector.Name, "perangkat", len(c.Devices))
	}
	return nil
}

func hint(code int) string {
	switch code {
	case http.StatusUnauthorized:
		return " (token salah, dicabut, atau collector dinonaktifkan di pusat)"
	case http.StatusNotFound:
		return " (alamat pusat salah atau versi web pusat belum mendukung collector site)"
	}
	return ""
}

func (a *Agent) pingCycle(ctx context.Context) {
	devs := a.snapshot()
	seen := map[string]bool{}
	var hosts []string
	for _, d := range devs {
		if d.PingEnabled && !seen[d.Host] {
			seen[d.Host] = true
			hosts = append(hosts, d.Host)
		}
	}
	now := time.Now().Truncate(time.Second)
	results := a.P.Ping.Many(ctx, hosts)
	if ctx.Err() != nil {
		return
	}
	b := Batch{Version: a.Version}
	if bad, msg := poller.LocalPingFailure(results); bad {
		a.Log.Error("semua ping gagal dijalankan di site, hasil tidak dikirim", "galat", msg)
	} else {
		for _, d := range devs {
			if d.PingEnabled {
				b.Pings = append(b.Pings, poller.PingSample{DeviceID: d.ID, At: now, Result: results[d.Host]})
			}
		}
	}
	// selalu diantrekan, juga tanpa sampel: menjadi detak bahwa collector site hidup
	a.enqueue(b)
	a.lastPing.Store(time.Now().Unix())
	down := 0
	for _, s := range b.Pings {
		if !s.Result.OK() {
			down++
		}
	}
	a.Log.Info("putaran ping", "perangkat", len(b.Pings), "tidak_menjawab", down)
}

func (a *Agent) snmpCycle(ctx context.Context) {
	devs := a.snapshot()
	jobs := make(chan DeviceConfig)
	out := make(chan poller.SNMPSample)
	workers := max(4, a.Cfg.Workers/8)
	var wg sync.WaitGroup
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for d := range jobs {
				s := a.P.MeasureSNMP(d.device(), d.SNMP.snmp(), nil, a.prof.For(d.ID))
				select {
				case out <- s:
				case <-ctx.Done():
					return
				}
			}
		}()
	}
	go func() {
		defer close(jobs)
		for _, d := range devs {
			if !d.SNMPEnabled || d.SNMP == nil { // kredensial belum diterima dari pusat
				continue
			}
			select {
			case jobs <- d:
			case <-ctx.Done():
				return
			}
		}
	}()
	go func() { wg.Wait(); close(out) }()
	var chunk []poller.SNMPSample
	n, fail := 0, 0
	for s := range out {
		chunk = append(chunk, s)
		n++
		if s.Err != "" {
			fail++
		}
		if len(chunk) >= snmpChunk {
			a.enqueue(Batch{Version: a.Version, SNMP: chunk})
			chunk = nil
		}
	}
	if len(chunk) > 0 {
		a.enqueue(Batch{Version: a.Version, SNMP: chunk})
	}
	if n > 0 {
		a.Log.Info("putaran SNMP", "perangkat", n, "gagal", fail)
	}
}

// checkCycle menjalankan cek layanan yang jatuh tempo (interval per cek), maks. 16 sekaligus.
func (a *Agent) checkCycle(ctx context.Context) {
	a.mu.Lock()
	if a.lastChk == nil {
		a.lastChk = map[int64]time.Time{}
	}
	var due []CheckConfig
	now := time.Now()
	for _, c := range a.checks {
		if last, ok := a.lastChk[c.ID]; !ok || now.Sub(last) >= time.Duration(c.IntervalS)*time.Second-5*time.Second {
			due = append(due, c)
			a.lastChk[c.ID] = now
		}
	}
	a.mu.Unlock()
	if len(due) == 0 {
		return
	}
	out := make([]CheckSample, len(due))
	var wg sync.WaitGroup
	sem := make(chan struct{}, 16)
	for i, c := range due {
		wg.Add(1)
		sem <- struct{}{}
		go func(i int, c CheckConfig) {
			defer wg.Done()
			defer func() { <-sem }()
			at := time.Now().Truncate(time.Second)
			out[i] = CheckSample{CheckID: c.ID, At: at, Result: checks.Run(ctx, c.spec())}
		}(i, c)
	}
	wg.Wait()
	a.enqueue(Batch{Version: a.Version, Checks: out})
}

// modbusCycle membaca semua perangkat ber-Modbus milik site ini.
func (a *Agent) modbusCycle(ctx context.Context) {
	a.mu.RLock()
	list := a.modbus
	a.mu.RUnlock()
	if len(list) == 0 {
		return
	}
	out := make([]ModbusSample, len(list))
	var wg sync.WaitGroup
	sem := make(chan struct{}, 8)
	for i, m := range list {
		wg.Add(1)
		sem <- struct{}{}
		go func(i int, m ModbusConfig) {
			defer wg.Done()
			defer func() { <-sem }()
			s := ModbusSample{DeviceID: m.DeviceID, At: time.Now().Truncate(time.Second)}
			rd, err := modbus.ReadAll(ctx, m.Host, m.Port, m.Unit, m.Points, 5*time.Second)
			if err != nil && len(rd) == 0 {
				s.Error = err.Error()
			}
			s.Readings = rd
			out[i] = s
		}(i, m)
	}
	wg.Wait()
	a.enqueue(Batch{Version: a.Version, Modbus: out})
}

// exporterCycle scrape exporter Prometheus perangkat milik site ini; status dinilai pusat dengan ambang perangkat.
func (a *Agent) exporterCycle(ctx context.Context) {
	a.mu.RLock()
	list := a.exporters
	a.mu.RUnlock()
	if len(list) == 0 {
		return
	}
	out := make([]ExporterSample, len(list))
	var wg sync.WaitGroup
	sem := make(chan struct{}, 8)
	for i, e := range list {
		wg.Add(1)
		sem <- struct{}{}
		go func(i int, e ExporterConfig) {
			defer wg.Done()
			defer func() { <-sem }()
			s := ExporterSample{DeviceID: e.DeviceID, At: time.Now().Truncate(time.Second)}
			rd, _, err := promx.Measure(ctx, &a.pxState, e.DeviceID, e.URL, e.Services)
			if err != nil {
				s.Error = err.Error()
			}
			s.Readings = rd
			out[i] = s
		}(i, e)
	}
	wg.Wait()
	a.enqueue(Batch{Version: a.Version, Exporters: out})
}

func (a *Agent) enqueue(b Batch) {
	dropped, err := a.Spool.Append(b)
	if err != nil {
		a.Log.Error("hasil tidak bisa diantrekan", "galat", err)
		return
	}
	if dropped > 0 {
		a.Log.Warn("antrean penuh, kiriman terlama dibuang", "berkas", dropped, "batas_mb", a.Cfg.SpoolMaxMB)
	}
}

// sendLoop mengirim antrean dari yang terlama. Jalur putus: coba lagi dengan jeda yang makin panjang (maks. 1 menit).
func (a *Agent) sendLoop(ctx context.Context) {
	backoff := time.Second
	wasDown := false
	for {
		files := a.Spool.Files()
		if len(files) == 0 {
			if !sleep(ctx, 2*time.Second) {
				return
			}
			continue
		}
		err := a.send(ctx, files[0], len(files)-1)
		switch {
		case err == nil:
			backoff = time.Second
			a.lastSent.Store(time.Now().Unix())
			a.lastError.Store("")
			if wasDown {
				a.Log.Info("jalur ke pusat pulih, antrean dikirim", "sisa", len(files)-1)
				wasDown = false
			}
			continue
		case errors.Is(err, errRejected):
			a.Log.Error("kiriman ditolak pusat dan disisihkan", "berkas", filepath.Base(files[0]), "galat", err)
			a.Spool.Reject(files[0])
			continue
		}
		if ctx.Err() != nil {
			return
		}
		a.lastError.Store(err.Error())
		if !wasDown {
			a.Log.Warn("pusat tidak bisa dihubungi, hasil diantrekan di site", "galat", err)
			wasDown = true
		}
		if !sleep(ctx, backoff) {
			return
		}
		backoff = min(backoff*2, time.Minute)
	}
}

var errRejected = errors.New("ditolak")

func (a *Agent) send(ctx context.Context, path string, queued int) error {
	body, err := a.Spool.Read(path)
	if err != nil {
		return fmt.Errorf("%w: berkas antrean tidak terbaca: %v", errRejected, err)
	}
	cctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	resp, err := a.request(cctx, http.MethodPost, "/api/site/v1/results", body, map[string]string{
		"Content-Type": "application/json", "Content-Encoding": "gzip",
		"X-Antareja-Sent-At": time.Now().UTC().Format(time.RFC3339), "X-Antareja-Queued": strconv.Itoa(queued),
		"X-Antareja-Version": a.Version,
	})
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	msg, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
	switch {
	case resp.StatusCode < 300:
		return a.Spool.Done(path)
	case resp.StatusCode == http.StatusBadRequest || resp.StatusCode == http.StatusRequestEntityTooLarge || resp.StatusCode == http.StatusUnprocessableEntity:
		return fmt.Errorf("%w: %s %s", errRejected, resp.Status, bytes.TrimSpace(msg))
	}
	return fmt.Errorf("pusat menjawab %s%s", resp.Status, hint(resp.StatusCode))
}

func sleep(ctx context.Context, d time.Duration) bool {
	select {
	case <-ctx.Done():
		return false
	case <-time.After(d):
		return true
	}
}
