package flow

import (
	"net/netip"
	"sort"
	"strconv"
	"sync"
	"time"
)

// Tampilan live: potongan 10 detik selama 5 menit terakhir, hanya untuk port yang sedang dibuka di web (ditonton).
// Port ditonton selama watchTTL sejak permintaan terakhir, jadi memori hanya terpakai untuk port yang dilihat.
const (
	LiveSlot    = 10 * time.Second
	LiveSlots   = 30
	watchTTL    = 2 * time.Minute
	maxWatched  = 50
	maxSlotKeys = 300 // kunci per potongan per pengelompokan; sisanya masuk "lainnya"
)

type portKey struct {
	dev int64
	ifi uint32
	dir byte
}

type slot struct {
	start      time.Time
	apps, srcs map[string]uint64 // "proto/port" dan IP sumber
	dsts       map[string]uint64
	total      uint64
}

type livePort struct {
	until time.Time
	slots [LiveSlots]*slot
}

// Live menyimpan potongan untuk port yang ditonton.
type Live struct {
	mu    sync.Mutex
	ports map[portKey]*livePort
}

// Watch menandai port ditonton (dipanggil saat web meminta data live).
func (l *Live) Watch(dev int64, ifi uint32, dir byte, now time.Time) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.ports == nil {
		l.ports = map[portKey]*livePort{}
	}
	k := portKey{dev, ifi, dir}
	if p, ok := l.ports[k]; ok {
		p.until = now.Add(watchTTL)
		return
	}
	for pk, p := range l.ports { // buang yang kedaluwarsa dulu
		if now.After(p.until) {
			delete(l.ports, pk)
		}
	}
	if len(l.ports) >= maxWatched {
		return
	}
	l.ports[k] = &livePort{until: now.Add(watchTTL)}
}

func (l *Live) add(dev int64, r Record, now time.Time) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if len(l.ports) == 0 {
		return
	}
	for _, side := range []struct {
		ifi uint32
		dir byte
	}{{r.InIf, 'i'}, {r.OutIf, 'o'}} {
		p, ok := l.ports[portKey{dev, side.ifi, side.dir}]
		if !ok || side.ifi == 0 || now.After(p.until) {
			continue
		}
		start := now.Truncate(LiveSlot)
		i := int(start.Unix()/int64(LiveSlot.Seconds())) % LiveSlots
		s := p.slots[i]
		if s == nil || !s.start.Equal(start) {
			s = &slot{start: start, apps: map[string]uint64{}, srcs: map[string]uint64{}, dsts: map[string]uint64{}}
			p.slots[i] = s
		}
		s.total += r.Bytes
		bump(s.apps, strconv.Itoa(int(r.Proto))+"/"+strconv.Itoa(int(ServicePort(r.Proto, r.SrcPort, r.DstPort))), r.Bytes)
		bump(s.srcs, addr(r.Src), r.Bytes)
		bump(s.dsts, addr(r.Dst), r.Bytes)
	}
}

func addr(a netip.Addr) string {
	if !a.IsValid() {
		return ""
	}
	return a.String()
}

func bump(m map[string]uint64, k string, b uint64) {
	if _, ok := m[k]; !ok && len(m) >= maxSlotKeys {
		k = "lainnya"
	}
	m[k] += b
}

// LivePoint satu potongan 10 detik: laju bit/s per kunci (sudah dipilih kunci teratas).
type LivePoint struct {
	T     int64              `json:"t"`
	Total float64            `json:"total"`
	By    map[string]float64 `json:"by"`
}

// Snapshot potongan 5 menit terakhir (yang sudah lewat, potongan berjalan tidak ikut supaya angka tidak setengah).
// by: app | src | dst. Kunci dibatasi top n berdasarkan total; sisanya digabung "lainnya".
func (l *Live) Snapshot(dev int64, ifi uint32, dir byte, by string, n int, now time.Time) []LivePoint {
	l.mu.Lock()
	defer l.mu.Unlock()
	p, ok := l.ports[portKey{dev, ifi, dir}]
	cur := now.Truncate(LiveSlot)
	var slots []*slot
	for i := LiveSlots; i >= 1; i-- {
		t := cur.Add(-time.Duration(i) * LiveSlot)
		var s *slot
		if ok {
			if x := p.slots[int(t.Unix()/int64(LiveSlot.Seconds()))%LiveSlots]; x != nil && x.start.Equal(t) {
				s = x
			}
		}
		if s == nil {
			s = &slot{start: t}
		}
		slots = append(slots, s)
	}
	pick := func(s *slot) map[string]uint64 {
		switch by {
		case "src":
			return s.srcs
		case "dst":
			return s.dsts
		}
		return s.apps
	}
	totals := map[string]uint64{}
	for _, s := range slots {
		for k, v := range pick(s) {
			totals[k] += v
		}
	}
	keys := make([]string, 0, len(totals))
	for k := range totals {
		if k != "lainnya" {
			keys = append(keys, k)
		}
	}
	sort.Slice(keys, func(i, j int) bool { return totals[keys[i]] > totals[keys[j]] })
	if len(keys) > n {
		keys = keys[:n]
	}
	top := map[string]bool{}
	for _, k := range keys {
		top[k] = true
	}
	rate := func(b uint64) float64 { return float64(b) * 8 / LiveSlot.Seconds() }
	out := make([]LivePoint, 0, len(slots))
	for _, s := range slots {
		pt := LivePoint{T: s.start.Unix(), Total: rate(s.total), By: map[string]float64{}}
		var other uint64
		for k, v := range pick(s) {
			if top[k] {
				pt.By[k] = rate(v)
			} else {
				other += v
			}
		}
		if other > 0 {
			pt.By["lainnya"] = rate(other)
		}
		out = append(out, pt)
	}
	return out
}
