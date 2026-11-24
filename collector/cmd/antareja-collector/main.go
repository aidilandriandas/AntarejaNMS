// antareja-collector — engine pemantauan ANTAREJA NMS (ping & SNMP, hanya baca).
//
// Perintah:
//
//	antareja-collector [run]            jalankan migrasi lalu putaran ping & SNMP terus-menerus
//	antareja-collector migrate          jalankan migrasi database saja
//	antareja-collector device add ...   tambah perangkat (kredensial dibaca dari stdin, tidak dari argumen)
//	antareja-collector device list      daftar perangkat & status
//	antareja-collector test ...         uji ping + SNMP ke satu host tanpa menyimpan
//	antareja-collector health           cek kesehatan proses yang sedang berjalan (untuk Docker)
package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"text/tabwriter"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/anomaly"
	apiserver "github.com/aidilandriandas/antarejanms/collector/internal/api"
	"github.com/aidilandriandas/antarejanms/collector/internal/cctv"
	"github.com/aidilandriandas/antarejanms/collector/internal/checks"
	"github.com/aidilandriandas/antarejanms/collector/internal/config"
	"github.com/aidilandriandas/antarejanms/collector/internal/configbak"
	"github.com/aidilandriandas/antarejanms/collector/internal/discovery"
	"github.com/aidilandriandas/antarejanms/collector/internal/flow"
	"github.com/aidilandriandas/antarejanms/collector/internal/incidents"
	"github.com/aidilandriandas/antarejanms/collector/internal/migrate"
	"github.com/aidilandriandas/antarejanms/collector/internal/modbus"
	"github.com/aidilandriandas/antarejanms/collector/internal/notify"
	"github.com/aidilandriandas/antarejanms/collector/internal/offsite"
	"github.com/aidilandriandas/antarejanms/collector/internal/ping"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/problems"
	"github.com/aidilandriandas/antarejanms/collector/internal/report"
	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
	"github.com/aidilandriandas/antarejanms/collector/internal/sitecollector"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
	"github.com/aidilandriandas/antarejanms/collector/internal/syslog"
	"github.com/aidilandriandas/antarejanms/collector/internal/system"
	"github.com/aidilandriandas/antarejanms/collector/internal/trap"
)

var version = "0.5.0"

func main() {
	args := os.Args[1:]
	cmd := "run"
	if len(args) > 0 {
		cmd, args = args[0], args[1:]
	}
	var err error
	switch cmd {
	case "run":
		err = run()
	case "migrate":
		err = withDB(func(ctx context.Context, _ config.Config, db *pgxpool.Pool) error {
			done, err := migrate.Run(ctx, db, mustCfg().MigrationsDir)
			fmt.Printf("migrasi diterapkan: %d %v\n", len(done), done)
			return err
		})
	case "device":
		err = device(args)
	case "test":
		err = testHost(args)
	case "health":
		err = health()
	case "version", "-v", "--version":
		fmt.Println("antareja-collector", version)
	default:
		err = fmt.Errorf("perintah tidak dikenal: %s (run | migrate | device add|list | test | health | version)", cmd)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "galat:", err)
		os.Exit(1)
	}
}

// runSite: collector site. Tanpa database dan kunci aplikasi; mengukur perangkat lokal lalu mengirim ke pusat.
func runSite(log *slog.Logger) error {
	cfg := mustCfg()
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	p := poller.New(cfg, store.Store{}, nil, log)
	agent, err := sitecollector.NewAgent(cfg, p, version, log)
	if err != nil {
		return err
	}
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		ok, msg := agent.Healthy()
		if !ok {
			w.WriteHeader(http.StatusServiceUnavailable)
		}
		fmt.Fprintln(w, msg)
	})
	srv := &http.Server{Addr: cfg.HealthAddr, Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	go func() {
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Error("server health gagal", "galat", err)
		}
	}()
	defer srv.Close()
	log.Info("collector site mulai", "versi", version, "pusat", cfg.CentralURL, "antrean", cfg.SpoolDir)
	return agent.Run(ctx)
}

func mustCfg() config.Config {
	c, err := config.Load()
	if err != nil {
		fmt.Fprintln(os.Stderr, "galat:", err)
		os.Exit(1)
	}
	return c
}

func withDB(fn func(context.Context, config.Config, *pgxpool.Pool) error) error {
	cfg := mustCfg()
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	db, err := connect(ctx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer db.Close()
	return fn(ctx, cfg, db)
}

func connect(ctx context.Context, url string) (*pgxpool.Pool, error) {
	var lastErr error
	for i := 0; i < 30; i++ { // database mungkin baru menyala (docker compose)
		db, err := pgxpool.New(ctx, url)
		if err == nil {
			if err = db.Ping(ctx); err == nil {
				return db, nil
			}
			db.Close()
		}
		lastErr = err
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(2 * time.Second):
		}
	}
	return nil, fmt.Errorf("tidak bisa terhubung ke database: %w", lastErr)
}

func run() error {
	log := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: level()}))
	if os.Getenv("ANTAREJA_MODE") == "site" {
		return runSite(log)
	}
	return withDB(func(ctx context.Context, cfg config.Config, db *pgxpool.Pool) error {
		box, err := secret.New(cfg.AppKey)
		if err != nil {
			return err
		}
		done, err := migrate.Run(ctx, db, cfg.MigrationsDir)
		if err != nil {
			return err
		}
		if len(done) > 0 {
			log.Info("migrasi diterapkan", "versi", done)
		}
		p := poller.New(cfg, store.Store{DB: db}, box, log)
		engine := &problems.Engine{DB: db, Stats: p.Stats}
		disp := notify.NewDispatcher(db, box, log, cfg.BaseURL)
		if cfg.TelegramAPI != "" {
			disp.Senders["telegram"] = notify.Telegram{BaseURL: cfg.TelegramAPI}
		}
		// Tombol ACK di pesan Telegram (hanya bot kanal yang mengaktifkannya)
		go (&notify.AckBot{Disp: disp, API: cfg.TelegramAPI, Log: log}).Run(ctx)
		inc := &incidents.Runner{DB: db, Log: log}
		var afterMu sync.Mutex // dipanggil putaran ping pusat dan kiriman collector site: jangan bersamaan
		p.AfterPing = func(ctx context.Context) {
			afterMu.Lock()
			defer afterMu.Unlock()
			out, err := engine.Run(ctx)
			if err != nil {
				log.Error("penilaian problem gagal", "galat", err)
				return
			}
			if err := inc.Run(ctx); err != nil {
				log.Warn("pengelompokan insiden gagal", "galat", err)
			}
			if out.Opened > 0 || out.Resolved > 0 {
				log.Info("problem", "baru", out.Opened, "selesai", out.Resolved, "terbuka", out.Open, "kondisi", out.Summary)
			}
			if err := disp.Run(ctx); err != nil {
				log.Error("notifikasi gagal", "galat", err)
			}
		}

		mux := http.NewServeMux()
		mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
			ok, msg := p.Healthy()
			if !ok {
				w.WriteHeader(http.StatusServiceUnavailable)
			}
			fmt.Fprintln(w, msg)
		})
		srv := &http.Server{Addr: cfg.HealthAddr, Handler: mux, ReadHeaderTimeout: 5 * time.Second}
		go func() {
			if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
				log.Error("server health gagal", "galat", err)
			}
		}()
		defer srv.Close()

		// Deteksi anomali: tiap 5 menit, mulai 2 menit setelah start supaya metrik 15 menit terakhir sudah ada
		if cfg.VMURL != "" {
			an := &anomaly.Runner{DB: db, VMURL: cfg.VMURL, Log: log}
			go func() {
				select {
				case <-ctx.Done():
					return
				case <-time.After(2 * time.Minute):
				}
				t := time.NewTicker(5 * time.Minute)
				defer t.Stop()
				for {
					rctx, cancel := context.WithTimeout(ctx, 4*time.Minute)
					if err := an.Run(rctx); err != nil && ctx.Err() == nil {
						log.Warn("deteksi anomali gagal", "galat", err)
					}
					cancel()
					select {
					case <-ctx.Done():
						return
					case <-t.C:
					}
				}
			}()
		}

		// Cek layanan (HTTP, TCP, DNS): yang jatuh tempo diperiksa tiap 15 detik
		checkRunner := &checks.Runner{DB: db, Log: log, Writer: p.Writer}
		go func() {
			t := time.NewTicker(15 * time.Second)
			defer t.Stop()
			for {
				rctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
				if err := checkRunner.Run(rctx); err != nil && ctx.Err() == nil {
					log.Warn("cek layanan gagal", "galat", err)
				}
				cancel()
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		// NetFlow v5/v9 dan IPFIX dari router (ringkasan 5 menit), retensi dibersihkan tiap jam
		if cfg.FlowUDP != "" {
			fc := &flow.Collector{DB: db, Log: log, Addr: cfg.FlowUDP}
			go func() {
				if err := fc.Run(ctx); err != nil {
					log.Error("penerima NetFlow gagal", "galat", err)
				}
			}()
			go func() {
				t := time.NewTicker(time.Hour)
				defer t.Stop()
				for {
					if n, err := flow.Prune(ctx, db); err != nil && ctx.Err() == nil {
						log.Warn("pembersihan flow gagal", "galat", err)
					} else if n > 0 {
						log.Info("ringkasan flow lama dihapus", "baris", n)
					}
					select {
					case <-ctx.Done():
						return
					case <-t.C:
					}
				}
			}()
		}

		// Modbus TCP (hanya baca) tiap menit: daya menara, baterai, genset
		modbusRunner := &modbus.Runner{DB: db, Log: log, Writer: p.Writer, Store: store.Store{DB: db}}
		go func() {
			t := time.NewTicker(time.Minute)
			defer t.Stop()
			for {
				rctx, cancel := context.WithTimeout(ctx, 50*time.Second)
				if err := modbusRunner.Run(rctx); err != nil && ctx.Err() == nil {
					log.Warn("putaran Modbus gagal", "galat", err)
				}
				cancel()
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		// Salinan backup NMS ke luar server (SFTP / S3), dicek tiap menit
		off := &offsite.Runner{DB: db, Box: box, Log: log, Dir: cfg.BackupDir}
		go func() {
			t := time.NewTicker(time.Minute)
			defer t.Stop()
			for {
				rctx, cancel := context.WithTimeout(ctx, 2*time.Hour)
				if err := off.Run(rctx); err != nil && ctx.Err() == nil {
					log.Warn("salinan backup ke luar server", "galat", err)
				}
				cancel()
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		// Rekap down bulanan per perangkat (tiap jam)
		go func() {
			mr := &report.MonthlyRunner{DB: db, Log: log}
			t := time.NewTicker(time.Hour)
			defer t.Stop()
			for {
				rctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
				if err := mr.Run(rctx); err != nil && ctx.Err() == nil {
					log.Warn("rekap down bulanan gagal", "galat", err)
				}
				cancel()
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		reports := &report.Runner{DB: db, Notify: disp, Log: log, BaseURL: strings.TrimRight(cfg.BaseURL, "/")}
		go func() {
			t := time.NewTicker(time.Minute)
			defer t.Stop()
			for {
				if err := reports.Run(ctx); err != nil && ctx.Err() == nil {
					log.Warn("laporan terjadwal gagal", "galat", err)
				}
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		if cfg.APIAddr != "" {
			api := &http.Server{Addr: cfg.APIAddr, Handler: (&apiserver.Server{Poller: p, Notify: disp, Report: reports.Preview, Offsite: off, Checks: checkRunner, Modbus: modbusRunner, Token: apiserver.Token(box.Key()), Log: log}).Handler(),
				ReadHeaderTimeout: 5 * time.Second, WriteTimeout: 60 * time.Second}
			go func() {
				if err := api.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
					log.Error("API internal gagal", "galat", err)
				}
			}()
			defer api.Close()
		}

		if cfg.Go2rtcURL != "" {
			chk := &cctv.Checker{DB: db, Box: box, Go2rtcURL: cfg.Go2rtcURL, Log: log}
			go func() {
				t := time.NewTicker(cfg.CCTVInterval)
				defer t.Stop()
				for {
					cctx, cancel := context.WithTimeout(ctx, cfg.CCTVInterval)
					if err := chk.Run(cctx); err != nil && ctx.Err() == nil {
						log.Warn("cek gambar CCTV gagal", "galat", err)
					}
					cancel()
					select {
					case <-ctx.Done():
						return
					case <-t.C:
					}
				}
			}()
		}

		// Backup konfigurasi via SSH (hanya baca): cek tiap menit untuk permintaan manual; tiap perangkat paling lambat 24 jam sekali
		cfgRunner := &configbak.Runner{DB: db, Box: box, Log: log, Every: 24 * time.Hour, Timeout: 20 * time.Second, Keep: 50}
		go func() {
			t := time.NewTicker(time.Minute)
			defer t.Stop()
			for {
				if err := cfgRunner.Run(ctx); err != nil && ctx.Err() == nil {
					log.Warn("backup konfigurasi gagal", "galat", err)
				}
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		// Discovery jaringan (diminta engineer dari web): satu pemindaian sekaligus, cek antrean tiap 5 detik
		disc := &discovery.Runner{DB: db, Box: box, Log: log, Privileged: cfg.PingPrivileged}
		go func() {
			t := time.NewTicker(5 * time.Second)
			defer t.Stop()
			for {
				if err := disc.Run(ctx); err != nil && ctx.Err() == nil {
					log.Warn("discovery gagal", "galat", err)
				}
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		mon := &system.Monitor{DB: db, Notify: disp, Log: log, Version: version, Started: time.Now(),
			C: system.Counters{LastPing: p.LastPing, LastSNMP: p.LastSNMP}}
		if cfg.SyslogUDP != "" || cfg.SyslogTCP != "" {
			sl := &syslog.Server{DB: db, Log: log, UDPAddr: cfg.SyslogUDP, TCPAddr: cfg.SyslogTCP}
			mon.C.Syslog = func() (int64, int64, int64) { return sl.Received.Load(), sl.Stored.Load(), sl.Dropped.Load() }
			go func() {
				if err := sl.Run(ctx); err != nil {
					log.Error("penerima syslog gagal", "galat", err)
				}
			}()
		}
		if cfg.TrapUDP != "" {
			ts := &trap.Server{DB: db, Log: log, Addr: cfg.TrapUDP, Poll: func(id int64) {
				pctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
				defer cancel()
				if err := p.PollNow(pctx, id); err != nil {
					log.Warn("pembacaan setelah trap gagal", "perangkat", id, "galat", err)
				}
			}}
			go func() {
				if err := ts.Run(ctx); err != nil {
					log.Error("penerima SNMP trap gagal", "galat", err)
				}
			}()
		}
		// Kesehatan ANTAREJA sendiri: detak tiap menit + peringatan sistem ke kanal notifikasi
		go func() {
			t := time.NewTicker(time.Minute)
			defer t.Stop()
			for {
				if err := mon.Run(ctx); err != nil && ctx.Err() == nil {
					log.Warn("pemantauan sistem gagal", "galat", err)
				}
				select {
				case <-ctx.Done():
					return
				case <-t.C:
				}
			}
		}()

		log.Info("ANTAREJA collector berjalan", "versi", version, "ping", cfg.PingInterval, "snmp", cfg.SNMPInterval,
			"metrik", cfg.VMURL != "")
		p.Run(ctx)
		log.Info("collector berhenti")
		return nil
	})
}

func level() slog.Level {
	if os.Getenv("ANTAREJA_DEBUG") == "true" {
		return slog.LevelDebug
	}
	return slog.LevelInfo
}

func health() error {
	addr := os.Getenv("ANTAREJA_HEALTH_ADDR")
	if addr == "" {
		addr = "127.0.0.1:9273"
	}
	c := http.Client{Timeout: 3 * time.Second}
	resp, err := c.Get("http://" + addr + "/healthz")
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("tidak sehat (%s)", resp.Status)
	}
	return nil
}

// ---------- kredensial (selalu dari stdin, tidak pernah dari argumen agar tidak tercatat di riwayat shell) ----------

type snmpFlags struct {
	version, user, level, authProto, privProto string
	port                                       int
	stdin                                      bool
}

func (s *snmpFlags) register(fs *flag.FlagSet) {
	fs.StringVar(&s.version, "snmp-version", "2c", "versi SNMP: 1, 2c, 3")
	fs.IntVar(&s.port, "snmp-port", 161, "port UDP SNMP")
	fs.BoolVar(&s.stdin, "snmp-stdin", false, "baca kredensial SNMP dari stdin (v1/v2c: community; v3: password auth lalu password privacy, satu per baris)")
	fs.StringVar(&s.user, "v3-user", "", "user SNMPv3")
	fs.StringVar(&s.level, "v3-level", "authPriv", "noAuthNoPriv | authNoPriv | authPriv")
	fs.StringVar(&s.authProto, "v3-auth", "SHA", "MD5 | SHA | SHA224 | SHA256 | SHA384 | SHA512")
	fs.StringVar(&s.privProto, "v3-priv", "AES", "DES | AES | AES192 | AES256")
}

// read mengembalikan kredensial polos.
func (s *snmpFlags) read() (snmp.Credentials, error) {
	c := snmp.Credentials{Version: s.version, Port: s.port}
	if !s.stdin {
		return c, nil
	}
	sc := bufio.NewScanner(os.Stdin)
	line := func(prompt string) string {
		fmt.Fprint(os.Stderr, prompt)
		if sc.Scan() {
			return strings.TrimRight(sc.Text(), "\r")
		}
		return ""
	}
	if s.version == "3" {
		c.User, c.Level, c.AuthProto, c.PrivProto = s.user, s.level, s.authProto, s.privProto
		if c.User == "" {
			return c, errors.New("--v3-user wajib untuk SNMPv3")
		}
		if s.level != "noAuthNoPriv" {
			c.AuthPass = line("Password auth SNMPv3: ")
		}
		if s.level == "authPriv" {
			c.PrivPass = line("Password privacy SNMPv3: ")
		}
	} else {
		c.Community = line("Community SNMP: ")
		if c.Community == "" {
			return c, errors.New("community kosong")
		}
	}
	return c, sc.Err()
}

func device(args []string) error {
	if len(args) == 0 {
		return errors.New("gunakan: device add | device list")
	}
	switch args[0] {
	case "list":
		return withDB(func(ctx context.Context, _ config.Config, db *pgxpool.Pool) error {
			rows, err := store.Store{DB: db}.List(ctx)
			if err != nil {
				return err
			}
			w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
			fmt.Fprintln(w, "ID\tNAMA\tHOST\tSTATUS\tRTT\tLOSS\tSNMP\tPORT\tSYSNAME / KETERANGAN")
			for _, r := range rows {
				rtt, loss, sn := "-", "-", "-"
				if r.RTT != nil {
					rtt = fmt.Sprintf("%.1f ms", *r.RTT)
				}
				if r.Loss != nil {
					loss = fmt.Sprintf("%.0f%%", *r.Loss)
				}
				if r.SNMPOK != nil {
					sn = map[bool]string{true: "ok", false: "gagal"}[*r.SNMPOK]
				}
				note := ""
				if r.SysName != nil {
					note = *r.SysName
				}
				if r.Status == "down" && r.Reason != nil {
					note = *r.Reason
				}
				fmt.Fprintf(w, "%d\t%s\t%s\t%s\t%s\t%s\t%s\t%d\t%s\n", r.ID, r.Name, r.Host, strings.ToUpper(r.Status), rtt, loss, sn, r.Ports, note)
			}
			return w.Flush()
		})
	case "add":
		fs := flag.NewFlagSet("device add", flag.ContinueOnError)
		name := fs.String("name", "", "nama perangkat (wajib)")
		host := fs.String("host", "", "IP atau nama DNS (wajib)")
		site := fs.String("site", "", "kode site, mis. PIT-A (dibuat otomatis bila belum ada)")
		cat := fs.String("category", "other", "kategori: core, distribution, access, wireless, ptp, cctv, nvr, server, ups, other")
		noPing := fs.Bool("no-ping", false, "jangan ping (perangkat memblokir ICMP; status dari SNMP)")
		var sf snmpFlags
		sf.register(fs)
		if err := fs.Parse(args[1:]); err != nil {
			return err
		}
		if *name == "" || *host == "" {
			return errors.New("--name dan --host wajib diisi")
		}
		cr, err := sf.read()
		if err != nil {
			return err
		}
		return withDB(func(ctx context.Context, cfg config.Config, db *pgxpool.Pool) error {
			box, err := secret.New(cfg.AppKey)
			if err != nil {
				return err
			}
			if _, err := migrate.Run(ctx, db, cfg.MigrationsDir); err != nil {
				return err
			}
			enc := func(s string) *string {
				if s == "" || err != nil {
					return nil
				}
				var e string
				e, err = box.Encrypt(s)
				return &e
			}
			nd := store.NewDevice{Name: *name, Host: *host, Category: *cat, SiteCode: *site, Ping: !*noPing,
				SNMP: sf.stdin, SNMPVersion: sf.version, SNMPPort: sf.port, CommunityEnc: enc(cr.Community)}
			if sf.version == "3" && sf.stdin {
				nd.V3User, nd.V3Level = &cr.User, &cr.Level
				if cr.Level != "noAuthNoPriv" {
					nd.V3AuthProto, nd.V3AuthEnc = &cr.AuthProto, enc(cr.AuthPass)
				}
				if cr.Level == "authPriv" {
					nd.V3PrivProto, nd.V3PrivEnc = &cr.PrivProto, enc(cr.PrivPass)
				}
			}
			if err != nil {
				return err
			}
			id, err := store.Store{DB: db}.AddDevice(ctx, nd)
			if err != nil {
				return err
			}
			fmt.Printf("perangkat #%d %q ditambahkan (ping: %v, SNMP: %v)\n", id, *name, nd.Ping, nd.SNMP)
			return nil
		})
	}
	return fmt.Errorf("subperintah device tidak dikenal: %s", args[0])
}

func testHost(args []string) error {
	fs := flag.NewFlagSet("test", flag.ContinueOnError)
	host := fs.String("host", "", "IP atau nama DNS (wajib)")
	var sf snmpFlags
	sf.register(fs)
	if err := fs.Parse(args); err != nil {
		return err
	}
	if *host == "" {
		return errors.New("--host wajib diisi")
	}
	cr, err := sf.read()
	if err != nil {
		return err
	}
	cfg, _ := config.Load() // DB tidak diperlukan; hanya pengaturan ping/SNMP
	pg := ping.Pinger{Count: max(cfg.PingCount, 3), Timeout: cfg.PingTimeout, Privileged: os.Getenv("ANTAREJA_PING_PRIVILEGED") == "true", Workers: 1}
	if pg.Timeout == 0 {
		pg.Timeout = 2 * time.Second
	}
	r := pg.One(context.Background(), *host)
	switch {
	case r.Err != "":
		fmt.Printf("PING  ✗ %s\n", r.Err)
	case r.OK():
		fmt.Printf("PING  ✓ %d/%d balasan, rata-rata %.2f ms, loss %.0f%%\n", r.Recv, r.Sent, r.AvgMS, r.LossPct)
	default:
		fmt.Printf("PING  ✗ tidak ada balasan dari %d paket (perangkat mati, rute salah, atau ICMP diblok firewall)\n", r.Sent)
	}
	if !sf.stdin {
		fmt.Println("SNMP  dilewati (tambahkan --snmp-stdin untuk menguji SNMP)")
		return nil
	}
	cl := snmp.Client{Timeout: 3 * time.Second, Retries: 1}
	sys, err := cl.System(*host, cr)
	if err != nil {
		fmt.Printf("SNMP  ✗ %s\n", snmp.Friendly(err))
		return nil
	}
	fmt.Printf("SNMP  ✓ %s · uptime %s\n      %s\n      sysObjectID %s\n", sys.Name, poller.Human(time.Duration(sys.UptimeSeconds())*time.Second), firstLine(sys.Descr), sys.ObjectID)
	ifs, err := cl.Interfaces(*host, cr)
	if err != nil {
		fmt.Printf("PORT  ✗ %s\n", snmp.Friendly(err))
		return nil
	}
	up := 0
	for _, i := range ifs {
		if i.OperUp {
			up++
		}
	}
	fmt.Printf("PORT  ✓ %d port terbaca (%d up)\n", len(ifs), up)
	return nil
}

func firstLine(s string) string {
	if i := strings.IndexAny(s, "\r\n"); i >= 0 {
		s = s[:i]
	}
	if len(s) > 120 {
		s = s[:120] + "…"
	}
	return s
}
