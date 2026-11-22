package syslog

import (
	"bufio"
	"context"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Server menerima syslog lewat UDP dan/atau TCP lalu menyimpannya per batch.
type Server struct {
	DB      *pgxpool.Pool
	Log     *slog.Logger
	UDPAddr string // mis. ":5514"; kosong = mati
	TCPAddr string
	// Batas per IP pengirim agar satu perangkat yang "banjir log" tidak membebani database.
	RatePerSec float64 // bawaan 200
	Burst      float64 // bawaan 1000

	ch      chan Message
	devices atomic.Pointer[map[netip.Addr]int64]
	buckets sync.Map // ip -> *bucket

	Received, Stored, Dropped atomic.Int64
}

type bucket struct {
	mu     sync.Mutex
	tokens float64
	last   time.Time
}

func (s *Server) allow(src string, now time.Time) bool {
	v, _ := s.buckets.LoadOrStore(src, &bucket{tokens: s.Burst, last: now})
	b := v.(*bucket)
	b.mu.Lock()
	defer b.mu.Unlock()
	b.tokens += now.Sub(b.last).Seconds() * s.RatePerSec
	if b.tokens > s.Burst {
		b.tokens = s.Burst
	}
	b.last = now
	if b.tokens < 1 {
		return false
	}
	b.tokens--
	return true
}

func (s *Server) push(b []byte, src string) {
	s.Received.Add(1)
	now := time.Now()
	if !s.allow(src, now) {
		s.Dropped.Add(1)
		return
	}
	select {
	case s.ch <- Parse(b, src, now):
	default: // antrean penuh (database lambat): buang daripada menghabiskan memori
		s.Dropped.Add(1)
	}
}

// Run menjalankan penerima, pencocokan perangkat, penulis batch, dan retensi sampai ctx selesai.
func (s *Server) Run(ctx context.Context) error {
	if s.RatePerSec <= 0 {
		s.RatePerSec = 200
	}
	if s.Burst <= 0 {
		s.Burst = 1000
	}
	s.ch = make(chan Message, 20000)
	empty := map[netip.Addr]int64{}
	s.devices.Store(&empty)
	s.refresh(ctx)

	var wg sync.WaitGroup
	if s.UDPAddr != "" {
		pc, err := net.ListenPacket("udp", s.UDPAddr)
		if err != nil {
			return err
		}
		if uc, ok := pc.(*net.UDPConn); ok {
			_ = uc.SetReadBuffer(4 << 20) // tahan lonjakan (mis. banyak perangkat reboot bersamaan)
		}
		go func() { <-ctx.Done(); pc.Close() }()
		wg.Add(1)
		go func() { defer wg.Done(); s.serveUDP(pc) }()
	}
	if s.TCPAddr != "" {
		ln, err := net.Listen("tcp", s.TCPAddr)
		if err != nil {
			return err
		}
		go func() { <-ctx.Done(); ln.Close() }()
		wg.Add(1)
		go func() { defer wg.Done(); s.serveTCP(ctx, ln) }()
	}
	s.Log.Info("penerima syslog berjalan", "udp", s.UDPAddr, "tcp", s.TCPAddr)

	go s.housekeeping(ctx)
	s.writer(ctx)
	wg.Wait()
	return nil
}

func (s *Server) serveUDP(pc net.PacketConn) {
	buf := make([]byte, 8192)
	for {
		n, addr, err := pc.ReadFrom(buf)
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return
			}
			continue
		}
		ip := addr.String()
		if ua, ok := addr.(*net.UDPAddr); ok {
			ip = ua.IP.String()
		}
		msg := make([]byte, n)
		copy(msg, buf[:n])
		s.push(msg, ip)
	}
}

const maxTCPConns = 500

func (s *Server) serveTCP(ctx context.Context, ln net.Listener) {
	sem := make(chan struct{}, maxTCPConns)
	for {
		c, err := ln.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return
			}
			continue
		}
		select {
		case sem <- struct{}{}:
		default:
			c.Close()
			continue
		}
		go func() {
			defer func() { <-sem }()
			defer c.Close()
			ip := c.RemoteAddr().String()
			if ta, ok := c.RemoteAddr().(*net.TCPAddr); ok {
				ip = ta.IP.String()
			}
			r := bufio.NewReaderSize(c, 16384)
			for ctx.Err() == nil {
				_ = c.SetReadDeadline(time.Now().Add(10 * time.Minute))
				msg, err := readFrame(r)
				if len(msg) > 0 {
					s.push(msg, ip)
				}
				if err != nil {
					return
				}
			}
		}()
	}
}

// readFrame membaca satu pesan TCP: octet-counting RFC 6587 ("123 <14>...") atau dipisah baris baru.
func readFrame(r *bufio.Reader) ([]byte, error) {
	b, err := r.Peek(1)
	if err != nil {
		return nil, err
	}
	if b[0] >= '1' && b[0] <= '9' {
		if head, err := r.Peek(8); err == nil || len(head) > 0 {
			if sp := strings.IndexByte(string(head), ' '); sp > 0 {
				if n, e := strconv.Atoi(string(head[:sp])); e == nil && n > 0 && n <= 65536 {
					if _, err := r.Discard(sp + 1); err != nil {
						return nil, err
					}
					msg := make([]byte, n)
					_, err := io.ReadFull(r, msg)
					return msg, err
				}
			}
		}
	}
	line, err := r.ReadSlice('\n')
	if errors.Is(err, bufio.ErrBufferFull) { // baris sangat panjang: ambil sebagian, buang sisanya
		out := append([]byte(nil), line...)
		for errors.Is(err, bufio.ErrBufferFull) {
			_, err = r.ReadSlice('\n')
		}
		return out, err
	}
	return append([]byte(nil), line...), err
}

// refresh memetakan IP → perangkat: host perangkat (bila berupa IP) dan semua IP interface dari IPAM.
func (s *Server) refresh(ctx context.Context) {
	rows, err := s.DB.Query(ctx, `SELECT id, host, 0 AS prio FROM devices UNION ALL SELECT device_id, host(ip), 1 FROM device_ips ORDER BY prio DESC`)
	if err != nil {
		s.Log.Warn("syslog: gagal membaca perangkat", "galat", err)
		return
	}
	defer rows.Close()
	m := map[netip.Addr]int64{}
	for rows.Next() {
		var id int64
		var host string
		var prio int
		if rows.Scan(&id, &host, &prio) != nil {
			continue
		}
		if a, err := netip.ParseAddr(host); err == nil {
			m[a.Unmap()] = id // host perangkat (prio 0) dibaca terakhir sehingga menang
		}
	}
	s.devices.Store(&m)
}

func (s *Server) deviceFor(src string) (netip.Addr, *int64) {
	a, err := netip.ParseAddr(src)
	if err != nil {
		return netip.Addr{}, nil
	}
	a = a.Unmap()
	if id, ok := (*s.devices.Load())[a]; ok {
		return a, &id
	}
	return a, nil
}

func (s *Server) writer(ctx context.Context) {
	batch := make([]Message, 0, 1000)
	t := time.NewTicker(time.Second)
	defer t.Stop()
	flush := func() {
		if len(batch) == 0 {
			return
		}
		fctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if err := s.store(fctx, batch); err != nil {
			s.Dropped.Add(int64(len(batch)))
			s.Log.Warn("syslog: simpan gagal", "jumlah", len(batch), "galat", err)
		} else {
			s.Stored.Add(int64(len(batch)))
		}
		batch = batch[:0]
	}
	for {
		select {
		case <-ctx.Done():
			// kosongkan antrean yang tersisa
			for {
				select {
				case m := <-s.ch:
					batch = append(batch, m)
				default:
					flush()
					return
				}
			}
		case m := <-s.ch:
			batch = append(batch, m)
			if len(batch) >= cap(batch) {
				flush()
			}
		case <-t.C:
			flush()
		}
	}
}

func (s *Server) store(ctx context.Context, batch []Message) error {
	rows := make([][]any, 0, len(batch))
	evSev := s.eventSeverity(ctx)
	type ev struct {
		id  int64
		msg string
	}
	var evs []ev
	for _, m := range batch {
		a, dev := s.deviceFor(m.Source)
		if !a.IsValid() {
			continue
		}
		var fac any
		if m.Facility >= 0 {
			fac = int16(m.Facility)
		}
		rows = append(rows, []any{m.Received, dev, a, fac, int16(m.Severity), nz(m.Host), nz(m.App), m.Text})
		if dev != nil && m.Severity <= evSev {
			evs = append(evs, ev{*dev, m.Text})
		}
	}
	if _, err := s.DB.CopyFrom(ctx, pgx.Identifier{"syslog"},
		[]string{"received_at", "device_id", "source", "facility", "severity", "host", "app", "message"}, pgx.CopyFromRows(rows)); err != nil {
		return err
	}
	// Log kritis → kejadian perangkat (paling banyak 1 per perangkat per 10 menit agar tidak membanjiri)
	seen := map[int64]bool{}
	for _, e := range evs {
		if seen[e.id] {
			continue
		}
		seen[e.id] = true
		msg := "Syslog kritis: " + e.msg
		if len(msg) > 480 {
			msg = strings.ToValidUTF8(msg[:480], "") + "…"
		}
		_, _ = s.DB.Exec(ctx, `INSERT INTO events (device_id, kind, message) SELECT $1, 'syslog', $2
			WHERE NOT EXISTS (SELECT 1 FROM events WHERE device_id = $1 AND kind = 'syslog' AND at > now() - interval '10 minutes')`, e.id, msg)
	}
	return nil
}

func nz(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func (s *Server) eventSeverity(ctx context.Context) int {
	var v int
	if err := s.DB.QueryRow(ctx, `SELECT value::int FROM settings WHERE key = 'syslog.event_severity'`).Scan(&v); err != nil {
		return 2
	}
	return v
}

// housekeeping: segarkan peta IP tiap menit, hapus log lama tiap jam, laporkan pesan yang dibuang.
func (s *Server) housekeeping(ctx context.Context) {
	t := time.NewTicker(time.Minute)
	defer t.Stop()
	var lastDropped int64
	n := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
		s.refresh(ctx)
		if d := s.Dropped.Load(); d > lastDropped {
			s.Log.Warn("syslog: pesan dibuang (batas laju/antrean penuh)", "jumlah", d-lastDropped)
			lastDropped = d
		}
		if n%60 == 0 {
			s.prune(ctx)
		}
		n++
		// buang bucket pengirim yang lama tidak aktif
		now := time.Now()
		s.buckets.Range(func(k, v any) bool {
			b := v.(*bucket)
			b.mu.Lock()
			old := now.Sub(b.last) > 10*time.Minute
			b.mu.Unlock()
			if old {
				s.buckets.Delete(k)
			}
			return true
		})
	}
}

func (s *Server) prune(ctx context.Context) {
	keep := 14
	_ = s.DB.QueryRow(ctx, `SELECT value::int FROM settings WHERE key = 'syslog.keep_days'`).Scan(&keep)
	if keep < 1 {
		keep = 1
	}
	var total int64
	for ctx.Err() == nil {
		tag, err := s.DB.Exec(ctx, `DELETE FROM syslog WHERE id IN (SELECT id FROM syslog WHERE received_at < now() - make_interval(days => $1) LIMIT 20000)`, keep)
		if err != nil {
			s.Log.Warn("syslog: retensi gagal", "galat", err)
			return
		}
		total += tag.RowsAffected()
		if tag.RowsAffected() < 20000 {
			break
		}
	}
	if total > 0 {
		s.Log.Info("syslog: log lama dihapus", "jumlah", total, "hari", keep)
	}
}
