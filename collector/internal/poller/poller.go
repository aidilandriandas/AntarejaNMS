// Package poller menjalankan putaran ping & SNMP, menilai status perangkat, dan mencatat kejadian.
package poller

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"math/big"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/config"
	"github.com/aidilandriandas/antarejanms/collector/internal/metrics"
	"github.com/aidilandriandas/antarejanms/collector/internal/ping"
	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

const (
	flapCount  = 3
	flapWindow = 15 * time.Minute
	eventKeep  = 90 * 24 * time.Hour
)

type Poller struct {
	Cfg    config.Config
	Store  store.Store
	Box    *secret.Box
	Ping   ping.Pinger
	SNMP   snmp.Client
	Writer metrics.Writer
	Log    *slog.Logger

	Stats *Stats
	// AfterPing dipanggil setelah setiap putaran ping (penilaian problem & notifikasi).
	AfterPing func(ctx context.Context)

	lastPing atomic.Int64 // unix detik putaran ping terakhir selesai
	lastSNMP atomic.Int64
	snmpMu   sync.Mutex // satu putaran SNMP pada satu waktu (terjadwal atau sinkron manual)
}

func New(cfg config.Config, st store.Store, box *secret.Box, log *slog.Logger) *Poller {
	return &Poller{
		Cfg: cfg, Store: st, Box: box, Log: log, Stats: NewStats(),
		Ping:   ping.Pinger{Count: cfg.PingCount, Timeout: cfg.PingTimeout, Privileged: cfg.PingPrivileged, Workers: cfg.Workers},
		SNMP:   snmp.Client{Timeout: cfg.SNMPTimeout, Retries: cfg.SNMPRetries},
		Writer: metrics.Writer{URL: cfg.VMURL},
	}
}

// Healthy: putaran ping terakhir selesai belum lebih dari 3× interval.
// LastPing waktu putaran ping terakhir selesai (nol bila belum ada).
func (p *Poller) LastPing() time.Time { return unixOrZero(p.lastPing.Load()) }

// LastSNMP waktu putaran SNMP terakhir selesai (nol bila belum ada).
func (p *Poller) LastSNMP() time.Time { return unixOrZero(p.lastSNMP.Load()) }

func unixOrZero(v int64) time.Time {
	if v == 0 {
		return time.Time{}
	}
	return time.Unix(v, 0)
}

func (p *Poller) Healthy() (bool, string) {
	last := p.lastPing.Load()
	if last == 0 {
		return false, "putaran ping pertama belum selesai"
	}
	age := time.Since(time.Unix(last, 0))
	if age > 3*p.Cfg.PingInterval {
		return false, fmt.Sprintf("putaran ping terakhir %s lalu", age.Round(time.Second))
	}
	return true, fmt.Sprintf("ping %s lalu, SNMP %s lalu", age.Round(time.Second), time.Since(time.Unix(p.lastSNMP.Load(), 0)).Round(time.Second))
}

// Run menjalankan kedua putaran sampai ctx dibatalkan. Putaran yang belum selesai tidak ditumpuk.
func (p *Poller) Run(ctx context.Context) {
	var wg sync.WaitGroup
	loop := func(name string, every time.Duration, fn func(context.Context) error) {
		defer wg.Done()
		t := time.NewTicker(every)
		defer t.Stop()
		for {
			cctx, cancel := context.WithTimeout(ctx, every)
			start := time.Now()
			if err := fn(cctx); err != nil && !errors.Is(err, context.Canceled) {
				p.Log.Error("putaran gagal", "putaran", name, "galat", err)
			} else {
				p.Log.Debug("putaran selesai", "putaran", name, "durasi", time.Since(start).Round(time.Millisecond))
			}
			cancel()
			select {
			case <-ctx.Done():
				return
			case <-t.C:
			}
		}
	}
	wg.Add(3)
	go loop("ping", p.Cfg.PingInterval, p.PingCycle)
	go loop("snmp", p.Cfg.SNMPInterval, p.SNMPCycle)
	go loop("perawatan", 24*time.Hour, func(ctx context.Context) error {
		if err := p.Store.PruneIPAM(ctx); err != nil {
			p.Log.Warn("pembersihan IPAM gagal", "galat", err)
		}
		if _, err := p.Store.PrunePortChanges(ctx, time.Now().Add(-eventKeep)); err != nil {
			p.Log.Warn("pembersihan riwayat port gagal", "galat", err)
		}
		n, err := p.Store.PruneEvents(ctx, time.Now().Add(-eventKeep))
		if n > 0 {
			p.Log.Info("kejadian lama dihapus", "jumlah", n)
		}
		return err
	})
	wg.Wait()
}

// ---------- Ping ----------

func (p *Poller) PingCycle(ctx context.Context) error {
	devs, err := p.Store.Devices(ctx)
	if err != nil {
		return err
	}
	var targets []store.Device
	seen := map[string]bool{}
	var hosts []string
	for _, d := range devs {
		if !d.PingEnabled {
			continue
		}
		targets = append(targets, d)
		if !seen[d.Host] {
			seen[d.Host] = true
			hosts = append(hosts, d.Host)
		}
	}
	now := time.Now().Truncate(time.Second)
	results := p.Ping.Many(ctx, hosts)
	if ctx.Err() != nil {
		return ctx.Err() // putaran terpotong: jangan menilai status dari hasil parsial
	}

	// Pengaman: bila SEMUA ping gagal karena galat lokal (mis. izin raw socket), jangan tandai semuanya down.
	localErr := len(results) > 0
	for _, r := range results {
		if r.OK() || r.Err == "" {
			localErr = false
			break
		}
	}
	if localErr {
		for _, r := range results {
			return fmt.Errorf("semua ping gagal dijalankan, status tidak diubah: %s", r.Err)
		}
	}

	var batch metrics.Batch
	ups := make([]store.PingUpdate, 0, len(targets))
	down := 0
	for _, d := range targets {
		r := results[d.Host]
		u := p.judgePing(ctx, d, r, now)
		p.Stats.AddPing(d.ID, r.OK(), r.AvgMS, r.LossPct)
		ups = append(ups, u)
		if u.Status == "down" {
			down++
		}
		l := map[string]string{"device_id": strconv.FormatInt(d.ID, 10)}
		batch.Add("antareja_ping_up", l, b2f(r.OK()), now)
		batch.Add("antareja_ping_loss_pct", l, r.LossPct, now)
		if r.OK() {
			batch.Add("antareja_ping_rtt_ms", l, r.AvgMS, now)
			batch.Add("antareja_ping_rtt_min_ms", l, r.MinMS, now)
			batch.Add("antareja_ping_rtt_max_ms", l, r.MaxMS, now)
			batch.Add("antareja_ping_jitter_ms", l, r.Jitter, now)
		}
	}
	if err := p.Store.SavePing(ctx, ups); err != nil {
		return err
	}
	if err := p.Writer.Write(ctx, &batch); err != nil {
		p.Log.Warn("metrik ping tidak terkirim", "galat", err)
	}
	p.lastPing.Store(time.Now().Unix())
	p.Log.Info("putaran ping", "perangkat", len(targets), "down", down)
	if p.AfterPing != nil {
		p.AfterPing(ctx)
	}
	return nil
}

func (p *Poller) judgePing(ctx context.Context, d store.Device, r ping.Result, now time.Time) store.PingUpdate {
	u := store.PingUpdate{ID: d.ID, Loss: r.LossPct, At: now, Status: d.Status}
	if r.OK() {
		rtt, jit := r.AvgMS, r.Jitter
		u.RTT, u.Jitter = &rtt, &jit
		u.FailStreak = 0
		u.Status = "up"
		th := p.Cfg.LatencyHighMS
		if lim := d.Limits(); lim.LatencyMS > 0 {
			th = lim.LatencyMS
		}
		if rtt > th && (d.LastRTT == nil || float64(*d.LastRTT) <= th) {
			p.event(ctx, d.ID, "latency_high", fmt.Sprintf("%s latensi tinggi %.0f ms (ambang %.0f ms)", d.Name, rtt, th), nil, now)
		}
	} else {
		u.FailStreak = d.FailStreak + 1
		reason := fmt.Sprintf("tidak menjawab ping (%d putaran)", u.FailStreak)
		if r.Err != "" {
			reason = r.Err
		}
		u.Reason = &reason
		if u.FailStreak >= p.Cfg.DownAfter {
			u.Status = "down"
		}
	}
	u.Changed = u.Status != d.Status
	if u.Changed {
		p.transition(ctx, d, u.Status, deref(u.Reason), now)
	}
	return u
}

// transition mencatat kejadian down/up (+ flapping) saat status berubah.
func (p *Poller) transition(ctx context.Context, d store.Device, status, reason string, now time.Time) {
	switch {
	case status == "down":
		p.event(ctx, d.ID, "down", fmt.Sprintf("%s DOWN: %s", d.Name, reason), nil, now)
		n, err := p.Store.RecentEvents(ctx, d.ID, "down", now.Add(-flapWindow))
		if err == nil && n >= flapCount {
			if f, _ := p.Store.RecentEvents(ctx, d.ID, "flapping", now.Add(-flapWindow)); f == 0 {
				p.event(ctx, d.ID, "flapping", fmt.Sprintf("%s naik-turun %d× dalam %d menit", d.Name, n, int(flapWindow.Minutes())), nil, now)
			}
		}
	case status == "up" && d.Status == "down":
		var dur *int64
		msg := d.Name + " kembali UP"
		if d.StatusChangedAt != nil {
			s := int64(now.Sub(*d.StatusChangedAt).Seconds())
			dur = &s
			msg += " setelah down " + Human(time.Duration(s)*time.Second)
		}
		p.event(ctx, d.ID, "up", msg, dur, now)
	}
}

// PollNow menjalankan ping & SNMP untuk satu perangkat segera (dipanggil web setelah perangkat ditambah/diubah).
func (p *Poller) PollNow(ctx context.Context, id int64) error {
	d, err := p.Store.Device(ctx, id)
	if err != nil {
		return err
	}
	now := time.Now().Truncate(time.Second)
	if d.PingEnabled {
		r := p.Ping.One(ctx, d.Host)
		u := p.judgePing(ctx, d, r, now)
		if err := p.Store.SavePing(ctx, []store.PingUpdate{u}); err != nil {
			return err
		}
		var batch metrics.Batch
		l := map[string]string{"device_id": strconv.FormatInt(d.ID, 10)}
		batch.Add("antareja_ping_up", l, b2f(r.OK()), now)
		batch.Add("antareja_ping_loss_pct", l, r.LossPct, now)
		if r.OK() {
			batch.Add("antareja_ping_rtt_ms", l, r.AvgMS, now)
			batch.Add("antareja_ping_rtt_min_ms", l, r.MinMS, now)
			batch.Add("antareja_ping_rtt_max_ms", l, r.MaxMS, now)
			batch.Add("antareja_ping_jitter_ms", l, r.Jitter, now)
		}
		if err := p.Writer.Write(ctx, &batch); err != nil {
			p.Log.Warn("metrik ping tidak terkirim", "galat", err)
		}
		if d, err = p.Store.Device(ctx, id); err != nil {
			return err
		}
	}
	if d.SNMPEnabled {
		p.pollSNMP(ctx, d)
	}
	return nil
}

// ---------- SNMP ----------

// ErrBusy: putaran SNMP lain sedang berjalan.
var ErrBusy = errors.New("putaran SNMP sedang berjalan")

// SNMPBusy: putaran SNMP sedang berjalan.
func (p *Poller) SNMPBusy() bool {
	if p.snmpMu.TryLock() {
		p.snmpMu.Unlock()
		return false
	}
	return true
}

// SyncNow menjalankan satu putaran SNMP penuh di luar jadwal (antarmuka, LLDP/CDP, pencocokan tetangga).
// Mengembalikan ErrBusy tanpa menunggu bila putaran lain sedang berjalan.
func (p *Poller) SyncNow(ctx context.Context) error {
	if !p.snmpMu.TryLock() {
		return ErrBusy
	}
	defer p.snmpMu.Unlock()
	return p.snmpCycle(ctx)
}

// SNMPCycle putaran terjadwal; dilewati bila sinkron manual masih berjalan.
func (p *Poller) SNMPCycle(ctx context.Context) error {
	if !p.snmpMu.TryLock() {
		p.Log.Info("putaran SNMP dilewati, sinkron manual masih berjalan")
		return nil
	}
	defer p.snmpMu.Unlock()
	return p.snmpCycle(ctx)
}

func (p *Poller) snmpCycle(ctx context.Context) error {
	devs, err := p.Store.Devices(ctx)
	if err != nil {
		return err
	}
	workers := max(8, p.Cfg.Workers/4)
	jobs := make(chan store.Device)
	var wg sync.WaitGroup
	var ok, fail atomic.Int64
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for d := range jobs {
				if p.pollSNMP(ctx, d) {
					ok.Add(1)
				} else {
					fail.Add(1)
				}
			}
		}()
	}
	for _, d := range devs {
		if !d.SNMPEnabled {
			continue
		}
		select {
		case jobs <- d:
		case <-ctx.Done():
		}
	}
	close(jobs)
	wg.Wait()
	// konteks sendiri: batas waktu putaran bisa sudah habis dipakai perangkat yang tidak menjawab
	mctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	if err := p.Store.RematchNeighbors(mctx); err != nil {
		p.Log.Warn("cocokkan ulang tetangga gagal", "galat", err)
	}
	p.lastSNMP.Store(time.Now().Unix())
	p.Log.Info("putaran SNMP", "berhasil", ok.Load(), "gagal", fail.Load())
	return ctx.Err()
}

// Credentials membuka kredensial terenkripsi perangkat.
func (p *Poller) Credentials(d store.Device) (snmp.Credentials, error) {
	c := snmp.Credentials{Version: d.SNMPVersion, Port: d.SNMPPort, User: deref(d.V3User), Level: deref(d.V3Level),
		AuthProto: deref(d.V3AuthProto), PrivProto: deref(d.V3PrivProto)}
	var err error
	open := func(enc *string) string {
		if enc == nil || *enc == "" || err != nil {
			return ""
		}
		var s string
		s, err = p.Box.Decrypt(*enc)
		return s
	}
	c.Community, c.AuthPass, c.PrivPass = open(d.CommunityEnc), open(d.V3AuthEnc), open(d.V3PrivEnc)
	if err != nil {
		return c, fmt.Errorf("kredensial SNMP tidak bisa dibuka (ANTAREJA_APP_KEY berbeda?): %w", err)
	}
	return c, nil
}

func (p *Poller) pollSNMP(ctx context.Context, d store.Device) bool {
	now := time.Now().Truncate(time.Second)
	u := store.SNMPUpdate{ID: d.ID, At: now}
	cr, err := p.Credentials(d)
	var sys snmp.System
	if err == nil {
		sys, err = p.SNMP.System(d.Host, cr)
	}
	if err != nil {
		msg := snmp.Friendly(err)
		u.Error = &msg
		if d.SNMPOK == nil || *d.SNMPOK {
			p.event(ctx, d.ID, "snmp_fail", d.Name+": SNMP tidak terbaca: "+msg, nil, now)
		}
		if !d.PingEnabled { // status hanya dari SNMP
			streak := d.FailStreak + 1
			u.FailStreak = &streak
			if streak >= p.Cfg.DownAfter {
				st := "down"
				u.Status, u.Reason = &st, &msg
				u.Changed = d.Status != "down"
				if u.Changed {
					p.transition(ctx, d, "down", msg, now)
				}
			}
		}
		if err := p.Store.SaveSNMP(ctx, u); err != nil {
			p.Log.Error("simpan SNMP gagal", "perangkat", d.ID, "galat", err)
		}
		p.Stats.SNMPResult(d.ID, false)
		return false
	}

	p.Stats.SNMPResult(d.ID, true)
	u.OK = true
	up := sys.UptimeSeconds()
	u.SysName, u.SysDescr, u.SysObjectID, u.SysLoc, u.Uptime = &sys.Name, &sys.Descr, &sys.ObjectID, &sys.Location, &up
	if d.SNMPOK != nil && !*d.SNMPOK {
		p.event(ctx, d.ID, "snmp_ok", d.Name+": SNMP kembali terbaca", nil, now)
	}
	if d.UptimeSeconds != nil && d.LastSNMPAt != nil &&
		snmp.Rebooted(uint32(*d.UptimeSeconds*100), sys.UptimeTicks, now.Sub(*d.LastSNMPAt)) {
		p.event(ctx, d.ID, "reboot", fmt.Sprintf("%s restart (uptime sebelumnya %s, sekarang %s)", d.Name,
			Human(time.Duration(*d.UptimeSeconds)*time.Second), Human(time.Duration(up)*time.Second)), nil, now)
	}
	if !d.PingEnabled {
		zero, st := 0, "up"
		u.FailStreak, u.Status = &zero, &st
		u.Changed = d.Status != "up"
		if u.Changed {
			p.transition(ctx, d, "up", "", now)
		}
	}
	if err := p.Store.SaveSNMP(ctx, u); err != nil {
		p.Log.Error("simpan SNMP gagal", "perangkat", d.ID, "galat", err)
	}

	var batch metrics.Batch
	dl := map[string]string{"device_id": strconv.FormatInt(d.ID, 10)}
	batch.Add("antareja_snmp_uptime_seconds", dl, float64(up), now)

	ifs, err := p.SNMP.Interfaces(d.Host, cr)
	if err != nil {
		p.Log.Warn("baca port gagal", "perangkat", d.ID, "galat", snmp.Friendly(err))
	} else {
		rows := make([]store.InterfaceRow, 0, len(ifs))
		for _, it := range ifs {
			inBps, outBps, util := p.Stats.Rate(d.ID, it.Index, it.InOctets, it.OutOctets, it.SpeedBps, now)
			errRate, discRate := p.Stats.ErrRate(d.ID, it.Index, it.InErrors, it.OutErrors, it.InDiscards, it.OutDiscards, now)
			rows = append(rows, store.InterfaceRow{Index: it.Index, Name: it.Name, Alias: it.Alias, Descr: it.Descr, MAC: it.MAC,
				Type: it.Type, SpeedBps: int64(it.SpeedBps), AdminUp: it.AdminUp, OperUp: it.OperUp, InBps: inBps, OutBps: outBps, Util: util,
				ErrRate: errRate, DiscardRate: discRate})
			l := map[string]string{"device_id": dl["device_id"], "if_index": strconv.Itoa(it.Index), "if_name": it.Name}
			batch.Add("antareja_if_oper_up", l, b2f(it.OperUp), now)
			batch.Add("antareja_if_speed_bps", l, float64(it.SpeedBps), now)
			addCounter(&batch, "antareja_if_in_octets_total", l, it.InOctets, now)
			addCounter(&batch, "antareja_if_out_octets_total", l, it.OutOctets, now)
			addCounter(&batch, "antareja_if_in_errors_total", l, it.InErrors, now)
			addCounter(&batch, "antareja_if_out_errors_total", l, it.OutErrors, now)
			addCounter(&batch, "antareja_if_in_ucast_pkts_total", l, it.InUcast, now)
			addCounter(&batch, "antareja_if_out_ucast_pkts_total", l, it.OutUcast, now)
			addCounter(&batch, "antareja_if_in_mcast_pkts_total", l, it.InMcast, now)
			addCounter(&batch, "antareja_if_out_mcast_pkts_total", l, it.OutMcast, now)
			addCounter(&batch, "antareja_if_in_bcast_pkts_total", l, it.InBcast, now)
			addCounter(&batch, "antareja_if_out_bcast_pkts_total", l, it.OutBcast, now)
			addCounter(&batch, "antareja_if_in_discards_total", l, it.InDiscards, now)
			addCounter(&batch, "antareja_if_out_discards_total", l, it.OutDiscards, now)
		}
		if err := p.Store.SaveInterfaces(ctx, d.ID, rows); err != nil {
			p.Log.Error("simpan port gagal", "perangkat", d.ID, "galat", err)
		}
		// Tetangga LLDP/CDP untuk peta topologi
		if nbs, err := p.SNMP.Neighbors(d.Host, cr, ifs); err == nil {
			nrows := make([]store.NeighborRow, 0, len(nbs))
			for _, n := range nbs {
				nrows = append(nrows, store.NeighborRow{LocalIfIndex: n.LocalIfIndex, LocalPort: n.LocalPortName, Protocol: n.Protocol,
					Name: n.RemoteName, Port: n.RemotePort, Chassis: n.RemoteChassis, IP: n.RemoteIP, Platform: n.RemotePlatform})
			}
			if err := p.Store.SaveNeighbors(ctx, d.ID, nrows); err != nil {
				p.Log.Error("simpan tetangga gagal", "perangkat", d.ID, "galat", err)
			}
		}
	}
	p.collectSensors(ctx, d, cr, sys.ObjectID, &batch, now)
	p.collectIPAM(ctx, d, cr, sys.ObjectID)
	if err := p.Writer.Write(ctx, &batch); err != nil {
		p.Log.Warn("metrik SNMP tidak terkirim", "perangkat", d.ID, "galat", err)
	}
	return true
}

func (p *Poller) event(ctx context.Context, id int64, kind, msg string, dur *int64, at time.Time) {
	if err := p.Store.Event(ctx, id, kind, msg, dur, at); err != nil {
		p.Log.Error("catat kejadian gagal", "perangkat", id, "jenis", kind, "galat", err)
		return
	}
	p.Log.Info("kejadian", "perangkat", id, "jenis", kind, "pesan", msg)
}

// Human menulis durasi dalam bahasa Indonesia singkat: "2 hari 3 jam", "5 menit", "40 detik".
func Human(d time.Duration) string {
	s := int64(d.Seconds())
	switch {
	case s < 60:
		return fmt.Sprintf("%d detik", s)
	case s < 3600:
		return fmt.Sprintf("%d menit", s/60)
	case s < 86400:
		if m := s % 3600 / 60; m > 0 {
			return fmt.Sprintf("%d jam %d menit", s/3600, m)
		}
		return fmt.Sprintf("%d jam", s/3600)
	default:
		if h := s % 86400 / 3600; h > 0 {
			return fmt.Sprintf("%d hari %d jam", s/86400, h)
		}
		return fmt.Sprintf("%d hari", s/86400)
	}
}

// addCounter menambah counter mentah; laju dihitung saat query (rate()). nil = tidak tersedia di perangkat.
func addCounter(b *metrics.Batch, name string, l map[string]string, v *big.Int, at time.Time) {
	if v == nil {
		return
	}
	f, _ := new(big.Float).SetInt(v).Float64()
	b.Add(name, l, f, at)
}

func b2f(b bool) float64 {
	if b {
		return 1
	}
	return 0
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}
