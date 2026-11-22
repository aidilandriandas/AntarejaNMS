package discovery

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/netip"
	"sync"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/ping"
	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
)

// MaxHosts batas alamat per pemindaian (setara /22) agar tidak membanjiri jaringan.
const MaxHosts = 1024

// Runner mengambil pemindaian berstatus queued satu per satu.
type Runner struct {
	DB         *pgxpool.Pool
	Box        *secret.Box
	Log        *slog.Logger
	Privileged bool // mode ping (sama dengan poller)
	Workers    int  // konkurensi (bawaan 64)
}

// Hosts menjabarkan prefix menjadi daftar alamat host (tanpa network & broadcast untuk IPv4 < /31).
func Hosts(cidr string) ([]netip.Addr, error) {
	p, err := netip.ParsePrefix(cidr)
	if err != nil {
		return nil, fmt.Errorf("format CIDR salah: %w", err)
	}
	p = p.Masked()
	if !p.Addr().Is4() {
		return nil, errors.New("hanya IPv4 yang didukung")
	}
	size := 1 << (32 - p.Bits())
	if size > MaxHosts+2 {
		return nil, fmt.Errorf("subnet terlalu besar (%d alamat); maksimal /22", size)
	}
	var out []netip.Addr
	a := p.Addr()
	for i := 0; i < size; i++ {
		if size > 2 && (i == 0 || i == size-1) {
			a = a.Next()
			continue
		}
		out = append(out, a)
		a = a.Next()
	}
	return out, nil
}

type scan struct {
	ID      int64
	CIDR    string
	Port    int
	CommEnc *string
	SNMPAll bool
	Comms   []string
}

// Run memproses satu pemindaian yang antre (bila ada). Dipanggil berkala.
func (r *Runner) Run(ctx context.Context) error {
	// Bersihkan community pemindaian lama (7 hari setelah selesai); hasil pemindaian tetap ada.
	_, _ = r.DB.Exec(ctx, `UPDATE discovery_scans SET communities_enc = NULL WHERE communities_enc IS NOT NULL AND finished_at < now() - interval '7 days'`)
	// Pemindaian "running" yang tertinggal karena collector restart → gagal
	_, _ = r.DB.Exec(ctx, `UPDATE discovery_scans SET status = 'failed', error = 'terhenti karena collector restart', finished_at = now()
		WHERE status = 'running' AND started_at < now() - interval '2 hours'`)

	var s scan
	err := r.DB.QueryRow(ctx, `UPDATE discovery_scans SET status = 'running', started_at = now()
		WHERE id = (SELECT id FROM discovery_scans WHERE status = 'queued' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
		RETURNING id, cidr::text, snmp_port, communities_enc, snmp_all`).Scan(&s.ID, &s.CIDR, &s.Port, &s.CommEnc, &s.SNMPAll)
	if err != nil {
		return nil // tidak ada antrean
	}
	if err := r.scan(ctx, &s); err != nil {
		msg := err.Error()
		if len(msg) > 250 {
			msg = msg[:250]
		}
		_, _ = r.DB.Exec(context.Background(), `UPDATE discovery_scans SET status = 'failed', error = $2, finished_at = now() WHERE id = $1`, s.ID, msg)
		r.Log.Warn("discovery gagal", "scan", s.ID, "galat", msg)
	}
	return nil
}

func (r *Runner) cancelled(ctx context.Context, id int64) bool {
	var st string
	_ = r.DB.QueryRow(ctx, `SELECT status FROM discovery_scans WHERE id = $1`, id).Scan(&st)
	return st == "cancelled"
}

func (r *Runner) scan(ctx context.Context, s *scan) error {
	hosts, err := Hosts(s.CIDR)
	if err != nil {
		return err
	}
	if s.CommEnc != nil {
		plain, err := r.Box.Decrypt(*s.CommEnc)
		if err != nil {
			return errors.New("community tidak bisa dibuka (ANTAREJA_APP_KEY berbeda?)")
		}
		if err := json.Unmarshal([]byte(plain), &s.Comms); err != nil {
			return errors.New("format community salah")
		}
	}
	_, _ = r.DB.Exec(ctx, `UPDATE discovery_scans SET total = $2 WHERE id = $1`, s.ID, len(hosts))
	r.Log.Info("discovery mulai", "scan", s.ID, "subnet", s.CIDR, "alamat", len(hosts), "community", len(s.Comms))

	// Perangkat terdaftar: host berupa IP + IP interface dari IPAM
	known := map[netip.Addr]int64{}
	if rows, err := r.DB.Query(ctx, `SELECT id, host FROM devices UNION ALL SELECT device_id, host(ip) FROM device_ips`); err == nil {
		for rows.Next() {
			var id int64
			var h string
			if rows.Scan(&id, &h) == nil {
				if a, err := netip.ParseAddr(h); err == nil {
					if _, dup := known[a]; !dup {
						known[a] = id
					}
				}
			}
		}
		rows.Close()
	}

	workers := r.Workers
	if workers <= 0 {
		workers = 64
	}
	pg := ping.Pinger{Count: 2, Timeout: time.Second, Privileged: r.Privileged, Workers: 1}
	cl := snmp.Client{Timeout: 1500 * time.Millisecond, Retries: 0}

	jobs := make(chan netip.Addr)
	var wg sync.WaitGroup
	var mu sync.Mutex
	done, found := 0, 0
	lastFlush := time.Now()
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for a := range jobs {
				ip := a.String()
				pr := pg.One(ctx, ip)
				res := result{ip: ip, pingOK: pr.OK(), rtt: pr.AvgMS}
				if (pr.OK() || s.SNMPAll) && len(s.Comms) > 0 {
					for idx, c := range s.Comms {
						sys, err := cl.System(ip, snmp.Credentials{Version: "2c", Port: s.Port, Community: c})
						if err == nil {
							res.snmpOK, res.commIdx, res.sys = true, idx, sys
							break
						}
					}
				}
				if id, ok := known[a]; ok {
					res.deviceID = &id
				}
				mu.Lock()
				done++
				if res.pingOK || res.snmpOK {
					found++
					mu.Unlock()
					r.save(ctx, s.ID, res)
					mu.Lock()
				}
				flush := time.Since(lastFlush) > 2*time.Second
				if flush {
					lastFlush = time.Now()
				}
				d, f := done, found
				mu.Unlock()
				if flush {
					_, _ = r.DB.Exec(ctx, `UPDATE discovery_scans SET progress = $2, found = $3 WHERE id = $1`, s.ID, d, f)
				}
			}
		}()
	}
	stopped := false
	for i, a := range hosts {
		if i%32 == 0 && r.cancelled(ctx, s.ID) {
			stopped = true
			break
		}
		select {
		case jobs <- a:
		case <-ctx.Done():
			stopped = true
		}
		if stopped {
			break
		}
	}
	close(jobs)
	wg.Wait()
	if stopped {
		_, _ = r.DB.Exec(context.Background(), `UPDATE discovery_scans SET progress = $2, found = $3, finished_at = now(),
			status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE 'failed' END,
			error = CASE WHEN status = 'cancelled' THEN NULL ELSE 'terhenti karena collector berhenti' END WHERE id = $1`, s.ID, done, found)
		return nil
	}
	_, _ = r.DB.Exec(ctx, `UPDATE discovery_scans SET status = 'done', progress = $2, found = $3, finished_at = now() WHERE id = $1`, s.ID, done, found)
	r.Log.Info("discovery selesai", "scan", s.ID, "ditemukan", found)
	return nil
}

type result struct {
	ip       string
	pingOK   bool
	rtt      float64
	snmpOK   bool
	commIdx  int
	sys      snmp.System
	deviceID *int64
}

func (r *Runner) save(ctx context.Context, scanID int64, x result) {
	vendor, cat := Classify(x.sys.ObjectID, x.sys.Descr, x.sys.Name)
	state := "new"
	if x.deviceID != nil {
		state = "exists"
	}
	var rtt, comm any
	if x.pingOK {
		rtt = x.rtt
	}
	if x.snmpOK {
		comm = int16(x.commIdx)
	}
	_, err := r.DB.Exec(ctx, `INSERT INTO discovery_results (scan_id, ip, ping_ok, rtt_ms, snmp_ok, community_idx, sys_name, sys_descr, sys_object_id, category, vendor, device_id, state)
		VALUES ($1, $2::inet, $3, $4, $5, $6, NULLIF($7, ''), NULLIF($8, ''), NULLIF($9, ''), $10, NULLIF($11, ''), $12, $13) ON CONFLICT (scan_id, ip) DO NOTHING`,
		scanID, x.ip, x.pingOK, rtt, x.snmpOK, comm, trim(x.sys.Name, 255), x.sys.Descr, trim(x.sys.ObjectID, 255), cat, vendor, x.deviceID, state)
	if err != nil {
		r.Log.Warn("discovery: simpan hasil gagal", "ip", x.ip, "galat", err)
	}
}

func trim(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
