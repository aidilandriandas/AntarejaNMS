// Package ping mengirim ICMP echo ke banyak host secara paralel (hanya baca).
package ping

import (
	"context"
	"sync"
	"time"

	probing "github.com/prometheus-community/pro-bing"
)

type Result struct {
	Host    string
	Sent    int
	Recv    int
	AvgMS   float64 // 0 bila tidak ada balasan
	MinMS   float64
	MaxMS   float64
	Jitter  float64 // simpangan baku RTT antarpaket (ms)
	LossPct float64
	Err     string // gagal resolve DNS / izin socket, dsb.
}

func (r Result) OK() bool { return r.Recv > 0 }

type Pinger struct {
	Count      int
	Timeout    time.Duration // batas tunggu setelah paket terakhir
	Privileged bool          // true = raw socket (butuh CAP_NET_RAW); false = ICMP datagram (butuh net.ipv4.ping_group_range)
	Workers    int
}

// Many melakukan ping ke semua host; hasil dikembalikan per host (urutan bebas).
func (p Pinger) Many(ctx context.Context, hosts []string) map[string]Result {
	out := make(map[string]Result, len(hosts))
	var mu sync.Mutex
	jobs := make(chan string)
	var wg sync.WaitGroup
	workers := p.Workers
	if workers > len(hosts) {
		workers = len(hosts)
	}
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for h := range jobs {
				r := p.One(ctx, h)
				mu.Lock()
				out[h] = r
				mu.Unlock()
			}
		}()
	}
	for _, h := range hosts {
		select {
		case jobs <- h:
		case <-ctx.Done():
		}
	}
	close(jobs)
	wg.Wait()
	return out
}

func (p Pinger) One(ctx context.Context, host string) Result {
	r := Result{Host: host, Sent: p.Count, LossPct: 100}
	pg, err := probing.NewPinger(host)
	if err != nil {
		r.Err = "alamat tidak bisa di-resolve: " + err.Error()
		return r
	}
	interval := 200 * time.Millisecond
	pg.Count = p.Count
	pg.Interval = interval
	pg.Timeout = time.Duration(p.Count-1)*interval + p.Timeout
	pg.SetPrivileged(p.Privileged)
	if err := pg.RunWithContext(ctx); err != nil {
		r.Err = "ping gagal dijalankan: " + err.Error()
		return r
	}
	st := pg.Statistics()
	r.Sent, r.Recv, r.LossPct = st.PacketsSent, st.PacketsRecv, st.PacketLoss
	if r.Sent == 0 {
		r.Sent = p.Count
	}
	if st.PacketsRecv > 0 {
		r.AvgMS = float64(st.AvgRtt.Microseconds()) / 1000
		r.MinMS = float64(st.MinRtt.Microseconds()) / 1000
		r.MaxMS = float64(st.MaxRtt.Microseconds()) / 1000
		r.Jitter = float64(st.StdDevRtt.Microseconds()) / 1000
	}
	return r
}
