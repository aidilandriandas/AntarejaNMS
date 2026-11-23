package checks

import (
	"context"
	"net"
	"strings"
	"testing"

	"golang.org/x/net/dns/dnsmessage"
)

// dnsTiruan menjawab A 10.1.2.3 untuk fms.tambang.local dan NXDOMAIN untuk nama lain.
func dnsTiruan(t *testing.T) int {
	t.Helper()
	pc, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { pc.Close() })
	go func() {
		buf := make([]byte, 1500)
		for {
			n, addr, err := pc.ReadFrom(buf)
			if err != nil {
				return
			}
			var m dnsmessage.Message
			if m.Unpack(buf[:n]) != nil || len(m.Questions) == 0 {
				continue
			}
			q := m.Questions[0]
			resp := dnsmessage.Message{Header: dnsmessage.Header{ID: m.ID, Response: true, Authoritative: true, RecursionAvailable: true}, Questions: m.Questions}
			switch {
			case q.Name.String() != "fms.tambang.local.":
				resp.RCode = dnsmessage.RCodeNameError
			case q.Type == dnsmessage.TypeA:
				resp.Answers = []dnsmessage.Resource{{Header: dnsmessage.ResourceHeader{Name: q.Name, Type: dnsmessage.TypeA, Class: dnsmessage.ClassINET, TTL: 60},
					Body: &dnsmessage.AResource{A: [4]byte{10, 1, 2, 3}}}}
			}
			out, _ := resp.Pack()
			_, _ = pc.WriteTo(out, addr)
		}
	}()
	return pc.LocalAddr().(*net.UDPAddr).Port
}

func TestDNS(t *testing.T) {
	port := dnsTiruan(t)
	ctx := context.Background()
	if r := Run(ctx, Spec{Type: "dns", Host: "127.0.0.1", Port: port, Query: "fms.tambang.local", ExpectAnswer: "10.1.2.3"}); !r.OK {
		t.Fatalf("nama dikenal: %+v", r)
	}
	if r := Run(ctx, Spec{Type: "dns", Host: "127.0.0.1", Port: port, Query: "fms.tambang.local", ExpectAnswer: "10.9.9.9"}); r.OK || !strings.Contains(r.Error, "tidak memuat") {
		t.Fatalf("jawaban berbeda: %+v", r)
	}
	if r := Run(ctx, Spec{Type: "dns", Host: "127.0.0.1", Port: port, Query: "hilang.tambang.local"}); r.OK || !strings.Contains(r.Error, "NXDOMAIN") {
		t.Fatalf("nama tidak ada: %+v", r)
	}
}
