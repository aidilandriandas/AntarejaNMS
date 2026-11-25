package flow

import (
	"context"
	"log/slog"
	"net"
	"net/netip"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Bucket lebar ringkasan dan jumlah percakapan terbesar yang disimpan per port per arah per bucket.
const (
	Bucket = 5 * time.Minute
	TopN   = 50
)

// Port layanan dikenal di atas 1024 (nomor IANA) supaya tidak dianggap port acak.
var knownHigh = map[uint16]bool{1194: true, 1433: true, 1883: true, 1935: true, 3306: true, 3389: true, 5060: true, 5432: true,
	5900: true, 8000: true, 8080: true, 8443: true, 8883: true, 9000: true}

// ServicePort menebak port layanan dari pasangan port: port terdaftar (< 1024 atau dikenal), bila tidak ada 0.
func ServicePort(proto uint8, sp, dp uint16) uint16 {
	if proto != 6 && proto != 17 {
		return 0
	}
	switch {
	case dp > 0 && dp < 1024:
		return dp
	case sp > 0 && sp < 1024:
		return sp
	case knownHigh[dp]:
		return dp
	case knownHigh[sp]:
		return sp
	}
	return 0
}

type aggKey struct {
	dev      int64
	ifIndex  uint32
	dir      byte // 'i' masuk lewat port ini, 'o' keluar
	src, dst netip.Addr
	proto    uint8
	port     uint16
}

type aggVal struct{ bytes, packets uint64 }

// Row satu baris ringkasan untuk disimpan. Src/Dst kosong = gabungan percakapan di luar TopN ("lainnya").
type Row struct {
	Bucket         time.Time
	DeviceID       int64
	IfIndex        uint32
	Dir            byte
	Src, Dst       netip.Addr
	Proto          uint8
	Port           uint16
	Bytes, Packets uint64
}

// Aggregator menjumlah record per bucket 5 menit.
type Aggregator struct {
	mu     sync.Mutex
	bucket time.Time
	m      map[aggKey]*aggVal
}

func (a *Aggregator) add(dev int64, r Record, now time.Time) []Row {
	a.mu.Lock()
	defer a.mu.Unlock()
	b := now.Truncate(Bucket)
	var flushed []Row
	if a.m != nil && !b.Equal(a.bucket) {
		flushed = a.drainLocked()
	}
	if a.m == nil {
		a.m = map[aggKey]*aggVal{}
	}
	a.bucket = b
	port := ServicePort(r.Proto, r.SrcPort, r.DstPort)
	for _, side := range []struct {
		ifi uint32
		dir byte
	}{{r.InIf, 'i'}, {r.OutIf, 'o'}} {
		if side.ifi == 0 {
			continue
		}
		k := aggKey{dev: dev, ifIndex: side.ifi, dir: side.dir, src: r.Src, dst: r.Dst, proto: r.Proto, port: port}
		v := a.m[k]
		if v == nil {
			if len(a.m) >= 500000 { // batas memori: percakapan baru diabaikan sampai bucket berikutnya
				continue
			}
			v = &aggVal{}
			a.m[k] = v
		}
		v.bytes += r.Bytes
		v.packets += r.Packets
	}
	return flushed
}

// Drain mengambil isi bucket berjalan (dipakai saat berhenti atau oleh pemicu waktu).
func (a *Aggregator) Drain(now time.Time) []Row {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.m == nil || now.Truncate(Bucket).Equal(a.bucket) {
		return nil
	}
	return a.drainLocked()
}

func (a *Aggregator) drainLocked() []Row {
	type grp struct {
		dev  int64
		ifi  uint32
		dir  byte
		rows []Row
	}
	groups := map[[3]int64]*grp{}
	for k, v := range a.m {
		g := groups[[3]int64{k.dev, int64(k.ifIndex), int64(k.dir)}]
		if g == nil {
			g = &grp{dev: k.dev, ifi: k.ifIndex, dir: k.dir}
			groups[[3]int64{k.dev, int64(k.ifIndex), int64(k.dir)}] = g
		}
		g.rows = append(g.rows, Row{Bucket: a.bucket, DeviceID: k.dev, IfIndex: k.ifIndex, Dir: k.dir, Src: k.src, Dst: k.dst, Proto: k.proto, Port: k.port, Bytes: v.bytes, Packets: v.packets})
	}
	var out []Row
	for _, g := range groups {
		sort.Slice(g.rows, func(i, j int) bool { return g.rows[i].Bytes > g.rows[j].Bytes })
		if len(g.rows) <= TopN {
			out = append(out, g.rows...)
			continue
		}
		out = append(out, g.rows[:TopN]...)
		other := Row{Bucket: a.bucket, DeviceID: g.dev, IfIndex: g.ifi, Dir: g.dir}
		for _, r := range g.rows[TopN:] {
			other.Bytes += r.Bytes
			other.Packets += r.Packets
		}
		out = append(out, other)
	}
	a.m = nil
	return out
}

// Collector penerima UDP: eksportir dikenali dari IP perangkat terdaftar (host atau IP interface).
type Collector struct {
	DB   *pgxpool.Pool
	Log  *slog.Logger
	Addr string // mis. ":2055" atau beberapa dipisah koma, mis. ":2055,:6343"

	dec       *Decoder
	agg       Aggregator
	Live      Live         // potongan 10 detik untuk port yang sedang dibuka di web
	exporters atomic.Value // map[netip.Addr]int64
	Received  atomic.Int64
	Unknown   atomic.Int64
	Bad       atomic.Int64
}

// Run mendengar sampai ctx dibatalkan.
func (c *Collector) Run(ctx context.Context) error {
	c.dec = NewDecoder()
	c.exporters.Store(map[netip.Addr]int64{})
	c.refresh(ctx)
	var conns []net.PacketConn
	for _, a := range strings.Split(c.Addr, ",") {
		if a = strings.TrimSpace(a); a == "" {
			continue
		}
		pc, err := net.ListenPacket("udp", a)
		if err != nil {
			for _, x := range conns {
				x.Close()
			}
			return err
		}
		conns = append(conns, pc)
	}
	if len(conns) == 0 {
		return nil
	}
	c.Log.Info("penerima NetFlow/IPFIX/sFlow", "alamat", c.Addr)
	go func() {
		<-ctx.Done()
		for _, x := range conns {
			x.Close()
		}
	}()
	go func() {
		t := time.NewTicker(time.Minute)
		defer t.Stop()
		for {
			select {
			case <-ctx.Done():
				c.save(context.Background(), c.agg.Drain(time.Now().Add(Bucket)))
				return
			case now := <-t.C:
				c.refresh(ctx)
				c.save(ctx, c.agg.Drain(now))
			}
		}
	}()
	var wg sync.WaitGroup
	for _, pc := range conns {
		wg.Add(1)
		go func(pc net.PacketConn) {
			defer wg.Done()
			c.read(ctx, pc)
		}(pc)
	}
	wg.Wait()
	return nil
}

func (c *Collector) read(ctx context.Context, pc net.PacketConn) {
	buf := make([]byte, 65535)
	for {
		n, addr, err := pc.ReadFrom(buf)
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			continue
		}
		ua, ok := addr.(*net.UDPAddr)
		if !ok {
			continue
		}
		ip, _ := netip.AddrFromSlice(ua.IP)
		ip = ip.Unmap()
		dev, known := c.exporters.Load().(map[netip.Addr]int64)[ip]
		if !known {
			c.Unknown.Add(1)
			continue
		}
		recs, err := c.dec.Decode(ip, buf[:n])
		if err != nil {
			c.Bad.Add(1)
		}
		now := time.Now()
		for _, r := range recs {
			c.Live.add(dev, r, now)
			if rows := c.agg.add(dev, r, now); rows != nil {
				go c.save(ctx, rows)
			}
		}
		c.Received.Add(int64(len(recs)))
	}
}

func (c *Collector) refresh(ctx context.Context) {
	m := map[netip.Addr]int64{}
	rows, err := c.DB.Query(ctx, `SELECT id, host FROM devices WHERE enabled
		UNION ALL SELECT di.device_id, host(di.ip) FROM device_ips di JOIN devices d ON d.id = di.device_id AND d.enabled`)
	if err != nil {
		return
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		var h string
		if rows.Scan(&id, &h) == nil {
			if a, err := netip.ParseAddr(h); err == nil {
				if _, dup := m[a]; !dup {
					m[a] = id
				}
			}
		}
	}
	c.exporters.Store(m)
}

func (c *Collector) save(ctx context.Context, rows []Row) {
	if len(rows) == 0 {
		return
	}
	data := make([][]any, 0, len(rows))
	for _, r := range rows {
		var src, dst any
		if r.Src.IsValid() {
			src, dst = r.Src.String(), r.Dst.String()
		}
		data = append(data, []any{r.Bucket, r.DeviceID, int64(r.IfIndex), string(r.Dir), src, dst, int16(r.Proto), int32(r.Port), int64(r.Bytes), int64(r.Packets)})
	}
	if _, err := c.DB.CopyFrom(ctx, pgx.Identifier{"flow_stats"},
		[]string{"bucket", "device_id", "if_index", "dir", "src", "dst", "proto", "port", "bytes", "packets"}, pgx.CopyFromRows(data)); err != nil {
		c.Log.Warn("simpan ringkasan flow gagal", "baris", len(rows), "galat", err)
		return
	}
	c.Log.Info("ringkasan flow disimpan", "baris", len(rows), "record_diterima", c.Received.Load(), "eksportir_tak_dikenal", c.Unknown.Load(), "paket_rusak", c.Bad.Load())
}

// Prune menghapus ringkasan lebih tua dari setelan flow.keep_days (bawaan 7 hari).
func Prune(ctx context.Context, db *pgxpool.Pool) (int64, error) {
	tag, err := db.Exec(ctx, `DELETE FROM flow_stats WHERE bucket < now() - make_interval(days => COALESCE(
		(SELECT (value #>> '{}')::int FROM settings WHERE key = 'flow.keep_days'), 7))`)
	return tag.RowsAffected(), err
}
