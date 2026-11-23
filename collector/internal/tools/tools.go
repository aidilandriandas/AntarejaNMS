// Package tools: alat diagnosa jaringan yang dijalankan dari collector atas permintaan engineer
// (ping, traceroute, cek port TCP). Hanya membaca/menguji jangkauan; tidak mengubah apa pun di perangkat.
package tools

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"net"
	"time"

	probing "github.com/prometheus-community/pro-bing"
	"golang.org/x/sys/unix"
)

// PingResult hasil ping beberapa paket.
type PingResult struct {
	Target  string     `json:"target"`
	Sent    int        `json:"sent"`
	Recv    int        `json:"recv"`
	LossPct float64    `json:"loss_pct"`
	MinMS   float64    `json:"min_ms"`
	AvgMS   float64    `json:"avg_ms"`
	MaxMS   float64    `json:"max_ms"`
	Jitter  float64    `json:"jitter_ms"`
	RTTs    []*float64 `json:"rtts_ms"` // per paket berurutan; nil = tidak dibalas
	Error   string     `json:"error,omitempty"`
}

// Ping mengirim count paket ICMP dengan selang 300 ms.
func Ping(ctx context.Context, host string, count int, privileged bool) PingResult {
	out := PingResult{Target: host}
	p, err := probing.NewPinger(host)
	if err != nil {
		out.Error = "alamat tidak bisa di-resolve: " + err.Error()
		return out
	}
	p.SetPrivileged(privileged)
	p.Count = count
	p.Interval = 300 * time.Millisecond
	p.Timeout = time.Duration(count)*300*time.Millisecond + 2*time.Second
	rtt := make([]*float64, count)
	p.OnRecv = func(pk *probing.Packet) {
		if pk.Seq >= 0 && pk.Seq < count {
			v := float64(pk.Rtt.Microseconds()) / 1000
			rtt[pk.Seq] = &v
		}
	}
	if err := p.RunWithContext(ctx); err != nil {
		out.Error = err.Error()
		return out
	}
	st := p.Statistics()
	out.Target = st.IPAddr.String()
	out.Sent, out.Recv, out.LossPct, out.RTTs = st.PacketsSent, st.PacketsRecv, st.PacketLoss, rtt
	ms := func(d time.Duration) float64 { return float64(d.Microseconds()) / 1000 }
	out.MinMS, out.AvgMS, out.MaxMS, out.Jitter = ms(st.MinRtt), ms(st.AvgRtt), ms(st.MaxRtt), ms(st.StdDevRtt)
	return out
}

// PortResult hasil cek port TCP.
type PortResult struct {
	Target string  `json:"target"`
	Port   int     `json:"port"`
	State  string  `json:"state"` // open | closed | timeout | error
	MS     float64 `json:"ms"`
	Detail string  `json:"detail,omitempty"`
}

// CheckPort mencoba membuka koneksi TCP (tanpa mengirim data) lalu langsung menutupnya.
func CheckPort(ctx context.Context, host string, port int) PortResult {
	out := PortResult{Target: host, Port: port}
	d := net.Dialer{Timeout: 4 * time.Second}
	start := time.Now()
	c, err := d.DialContext(ctx, "tcp", net.JoinHostPort(host, fmt.Sprint(port)))
	out.MS = float64(time.Since(start).Microseconds()) / 1000
	if err == nil {
		c.Close()
		out.State = "open"
		return out
	}
	var ne net.Error
	switch {
	case errors.Is(err, unix.ECONNREFUSED):
		out.State, out.Detail = "closed", "perangkat menjawab tetapi port tidak melayani (ditolak)"
	case errors.As(err, &ne) && ne.Timeout():
		out.State, out.Detail = "timeout", "tidak ada jawaban (port difilter firewall, atau perangkat tidak terjangkau)"
	default:
		out.State, out.Detail = "error", err.Error()
	}
	return out
}

// Hop satu langkah traceroute.
type Hop struct {
	TTL     int      `json:"ttl"`
	Address string   `json:"address,omitempty"` // kosong = tidak ada jawaban
	MS      *float64 `json:"ms,omitempty"`
	Note    string   `json:"note,omitempty"`
	// Unreachable: router (bukan tujuan) menjawab destination unreachable
	Unreachable bool `json:"unreachable,omitempty"`
}

// TraceResult hasil traceroute.
type TraceResult struct {
	Target  string `json:"target"`
	Reached bool   `json:"reached"`
	Hops    []Hop  `json:"hops"`
	Error   string `json:"error,omitempty"`
}

// Traceroute ICMP tanpa hak root: soket ICMP datagram (net.ipv4.ping_group_range) dengan TTL yang dinaikkan per hop;
// jawaban "time exceeded" dari router dibaca dari antrean error soket (IP_RECVERR). Satu probe per hop, batas 1 detik.
func Traceroute(ctx context.Context, host string, maxHops int) TraceResult {
	out := TraceResult{Target: host, Hops: []Hop{}}
	ips, err := net.DefaultResolver.LookupIP(ctx, "ip4", host)
	if err != nil || len(ips) == 0 {
		out.Error = "alamat IPv4 tidak ditemukan untuk " + host
		return out
	}
	dst := ips[0].To4()
	out.Target = dst.String()
	silent := 0
	for ttl := 1; ttl <= maxHops; ttl++ {
		if ctx.Err() != nil {
			break
		}
		h, reached, err := probe(dst, ttl, time.Second)
		if err != nil {
			out.Error = err.Error()
			return out
		}
		out.Hops = append(out.Hops, h)
		if reached {
			out.Reached = true
			break
		}
		if h.Unreachable {
			break // router menyatakan tujuan tidak terjangkau: jalur berhenti di sini
		}
		if h.Address == "" {
			silent++
			if silent >= 5 { // lima hop berturut-turut diam: hentikan, sisanya hampir pasti ikut diam
				break
			}
		} else {
			silent = 0
		}
	}
	return out
}

func probe(dst net.IP, ttl int, timeout time.Duration) (Hop, bool, error) {
	h := Hop{TTL: ttl}
	fd, err := unix.Socket(unix.AF_INET, unix.SOCK_DGRAM|unix.SOCK_CLOEXEC, unix.IPPROTO_ICMP)
	if err != nil {
		return h, false, fmt.Errorf("soket ICMP tidak bisa dibuka (periksa net.ipv4.ping_group_range): %w", err)
	}
	defer unix.Close(fd)
	if err := unix.SetsockoptInt(fd, unix.IPPROTO_IP, unix.IP_TTL, ttl); err != nil {
		return h, false, err
	}
	if err := unix.SetsockoptInt(fd, unix.IPPROTO_IP, unix.IP_RECVERR, 1); err != nil {
		return h, false, err
	}
	// echo request: tipe 8; id & checksum diisi kernel untuk soket ICMP datagram
	pkt := make([]byte, 8+24)
	pkt[0] = 8
	binary.BigEndian.PutUint16(pkt[6:], uint16(ttl))
	copy(pkt[8:], "ANTAREJA-NMS traceroute")
	var sa unix.SockaddrInet4
	copy(sa.Addr[:], dst)
	start := time.Now()
	if err := unix.Sendto(fd, pkt, 0, &sa); err != nil {
		h.Note = "gagal kirim: " + err.Error()
		return h, false, nil
	}
	buf := make([]byte, 512)
	oob := make([]byte, 512)
	for {
		left := timeout - time.Since(start)
		if left <= 0 {
			return h, false, nil
		}
		fds := []unix.PollFd{{Fd: int32(fd), Events: unix.POLLIN}}
		n, err := unix.Poll(fds, int(left.Milliseconds())+1)
		if err != nil {
			if errors.Is(err, unix.EINTR) {
				continue
			}
			return h, false, err
		}
		if n == 0 {
			return h, false, nil
		}
		ms := float64(time.Since(start).Microseconds()) / 1000
		if fds[0].Revents&unix.POLLERR != 0 {
			_, oobn, _, _, err := unix.Recvmsg(fd, buf, oob, unix.MSG_ERRQUEUE|unix.MSG_DONTWAIT)
			if err != nil {
				continue
			}
			addr, typ, code, ok := parseRecvErr(oob[:oobn])
			if !ok {
				continue
			}
			h.Address, h.MS = addr, &ms
			switch typ {
			case 11: // time exceeded: router di tengah jalan
				return h, false, nil
			case 3:
				h.Note = unreachText(code)
				if addr == dst.String() {
					return h, true, nil // tujuan sendiri menjawab (mis. port/protokol tidak terjangkau): sudah sampai
				}
				h.Unreachable = true
				return h, false, nil
			default:
				h.Note = fmt.Sprintf("ICMP tipe %d kode %d", typ, code)
				return h, false, nil
			}
		}
		if fds[0].Revents&unix.POLLIN != 0 {
			n, from, err := unix.Recvfrom(fd, buf, unix.MSG_DONTWAIT)
			if err != nil || n < 1 || buf[0] != 0 { // 0 = echo reply
				continue
			}
			if s4, ok := from.(*unix.SockaddrInet4); ok {
				h.Address = net.IP(s4.Addr[:]).String()
			}
			h.MS = &ms
			return h, true, nil
		}
	}
}

// parseRecvErr membaca sock_extended_err + alamat pengirim ICMP dari pesan kontrol IP_RECVERR.
func parseRecvErr(oob []byte) (addr string, typ, code uint8, ok bool) {
	msgs, err := unix.ParseSocketControlMessage(oob)
	if err != nil {
		return "", 0, 0, false
	}
	for _, m := range msgs {
		if m.Header.Level != unix.IPPROTO_IP || m.Header.Type != unix.IP_RECVERR || len(m.Data) < 16+16 {
			continue
		}
		// struct sock_extended_err { u32 errno; u8 origin; u8 type; u8 code; u8 pad; u32 info; u32 data; } lalu sockaddr_in
		if m.Data[4] != unix.SO_EE_ORIGIN_ICMP {
			continue
		}
		typ, code = m.Data[5], m.Data[6]
		sa := m.Data[16:]
		if binary.LittleEndian.Uint16(sa[0:2]) != unix.AF_INET && binary.BigEndian.Uint16(sa[0:2]) != unix.AF_INET {
			continue
		}
		return net.IP(sa[4:8]).String(), typ, code, true
	}
	return "", 0, 0, false
}

func unreachText(code uint8) string {
	switch code {
	case 0:
		return "jaringan tidak terjangkau"
	case 1:
		return "host tidak terjangkau"
	case 3:
		return "port tidak terjangkau"
	case 9, 10, 13:
		return "diblokir (administratively prohibited)"
	}
	return fmt.Sprintf("tidak terjangkau (kode %d)", code)
}
