package trap

import (
	"context"
	"encoding/json"
	"log/slog"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"

	"github.com/gosnmp/gosnmp"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Server penerima trap UDP. Satu goroutine membaca, satu menulis ke database.
type Server struct {
	DB   *pgxpool.Pool
	Log  *slog.Logger
	Addr string // mis. ":5162"
	// Poll dipanggil (di goroutine sendiri) untuk trap link/restart/UPS dari perangkat terdaftar.
	Poll func(deviceID int64)

	ch       chan item
	devices  atomic.Pointer[map[netip.Addr]int64]
	ports    atomic.Pointer[map[[2]int64]string]
	mu       sync.Mutex
	perIP    map[netip.Addr]*window
	lastPoll map[int64]time.Time

	Received, Stored, Dropped atomic.Int64
}

type item struct {
	at  time.Time
	src netip.Addr
	pkt *gosnmp.SnmpPacket
}

// window: batas 50 trap per 10 detik per IP pengirim (badai linkUp/linkDown dari port yang flapping).
type window struct {
	start time.Time
	n     int
}

const (
	perWindow   = 50
	windowLen   = 10 * time.Second
	pollDebounc = 30 * time.Second
)

func (s *Server) allow(a netip.Addr, now time.Time) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	w := s.perIP[a]
	if w == nil || now.Sub(w.start) > windowLen {
		s.perIP[a] = &window{start: now, n: 1}
		return true
	}
	w.n++
	return w.n <= perWindow
}

// Run menerima sampai ctx selesai.
func (s *Server) Run(ctx context.Context) error {
	pc, err := net.ListenPacket("udp", s.Addr)
	if err != nil {
		return err
	}
	s.ch = make(chan item, 5000)
	s.perIP = map[netip.Addr]*window{}
	s.lastPoll = map[int64]time.Time{}
	s.refresh(ctx)
	go func() { <-ctx.Done(); pc.Close() }()
	go s.housekeeping(ctx)
	go s.writer(ctx)
	s.Log.Info("penerima SNMP trap berjalan", "udp", s.Addr)

	dec := &gosnmp.GoSNMP{}
	buf := make([]byte, 65535)
	for {
		n, addr, err := pc.ReadFrom(buf)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			continue
		}
		s.Received.Add(1)
		ua, ok := addr.(*net.UDPAddr)
		if !ok {
			continue
		}
		src, _ := netip.AddrFromSlice(ua.IP)
		src = src.Unmap()
		now := time.Now()
		if !s.allow(src, now) {
			s.Dropped.Add(1)
			continue
		}
		pkt, err := dec.UnmarshalTrap(append([]byte(nil), buf[:n]...), false)
		if err != nil || pkt == nil || pkt.Version == gosnmp.Version3 {
			s.Dropped.Add(1) // bukan trap v1/v2c yang sah
			continue
		}
		select {
		case s.ch <- item{at: now, src: src, pkt: pkt}:
		default:
			s.Dropped.Add(1)
		}
	}
}

func (s *Server) writer(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			return
		case it := <-s.ch:
			if err := s.store(ctx, it); err != nil {
				s.Dropped.Add(1)
				if ctx.Err() == nil {
					s.Log.Warn("trap: simpan gagal", "galat", err)
				}
				continue
			}
			s.Stored.Add(1)
		}
	}
}

func (s *Server) store(ctx context.Context, it item) error {
	var dev *int64
	if id, ok := (*s.devices.Load())[it.src]; ok {
		dev = &id
	}
	d := Decode(it.pkt, func(idx int) string {
		if dev == nil {
			return ""
		}
		return (*s.ports.Load())[[2]int64{*dev, int64(idx)}]
	})
	vb, _ := json.Marshal(d.Varbinds)
	var name *string
	if d.Name != "" {
		name = &d.Name
	}
	wctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if _, err := s.DB.Exec(wctx, `INSERT INTO traps (received_at, device_id, source, version, trap_oid, name, severity, if_index, summary, varbinds)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`, it.at, dev, it.src.String(), d.Version, trim(d.OID, 255), name, d.Severity, d.IfIndex, d.Summary, vb); err != nil {
		return err
	}
	if dev == nil {
		return nil
	}
	if (d.Name == "linkDown" || d.Name == "linkUp") && d.IfIndex != nil {
		_, _ = s.DB.Exec(wctx, `INSERT INTO port_changes (device_id, if_index, up, source, at) VALUES ($1, $2, $3, 'trap', $4)`, *dev, *d.IfIndex, d.Name == "linkUp", it.at)
	}
	if d.Name != "" && d.Name != "warmStart" && d.Name != "upsTrapTestCompleted" {
		_, _ = s.DB.Exec(wctx, `INSERT INTO events (device_id, kind, message, at) VALUES ($1, 'trap', $2, $3)`, *dev, d.Summary, it.at)
	}
	if d.Poll && s.Poll != nil {
		s.mu.Lock()
		last := s.lastPoll[*dev]
		due := it.at.Sub(last) >= pollDebounc
		if due {
			s.lastPoll[*dev] = it.at
		}
		s.mu.Unlock()
		if due {
			go s.Poll(*dev)
		}
	}
	return nil
}

// refresh memetakan IP pengirim ke perangkat (host dan IP interface dari IPAM) serta nama port.
func (s *Server) refresh(ctx context.Context) {
	m := map[netip.Addr]int64{}
	rows, err := s.DB.Query(ctx, `SELECT id, host, 0 AS prio FROM devices UNION ALL SELECT device_id, host(ip), 1 FROM device_ips ORDER BY prio DESC`)
	if err == nil {
		for rows.Next() {
			var id int64
			var host string
			var prio int
			if rows.Scan(&id, &host, &prio) == nil {
				if a, err := netip.ParseAddr(host); err == nil {
					m[a.Unmap()] = id
				}
			}
		}
		rows.Close()
	} else {
		s.Log.Warn("trap: gagal membaca perangkat", "galat", err)
	}
	s.devices.Store(&m)
	p := map[[2]int64]string{}
	if rows, err := s.DB.Query(ctx, `SELECT device_id, if_index, COALESCE(NULLIF(name, ''), descr, '') FROM interfaces`); err == nil {
		for rows.Next() {
			var d, i int64
			var n string
			if rows.Scan(&d, &i, &n) == nil {
				p[[2]int64{d, i}] = n
			}
		}
		rows.Close()
	}
	s.ports.Store(&p)
}

func (s *Server) housekeeping(ctx context.Context) {
	t := time.NewTicker(time.Minute)
	defer t.Stop()
	n := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
		s.refresh(ctx)
		s.mu.Lock()
		now := time.Now()
		for a, w := range s.perIP {
			if now.Sub(w.start) > time.Minute {
				delete(s.perIP, a)
			}
		}
		s.mu.Unlock()
		if n%60 == 0 { // tiap jam: buang trap lama
			keep := 30
			_ = s.DB.QueryRow(ctx, `SELECT value::text::int FROM settings WHERE key = 'trap.keep_days'`).Scan(&keep)
			if keep < 1 {
				keep = 1
			}
			_, _ = s.DB.Exec(ctx, `DELETE FROM traps WHERE received_at < now() - make_interval(days => $1)`, keep)
		}
		n++
	}
}
