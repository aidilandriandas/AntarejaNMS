package modbus

import (
	"encoding/binary"
	"io"
	"net"
	"sync"
	"testing"
)

// fakeServer server Modbus TCP tiruan: register holding & input dari peta, fungsi lain → pengecualian 01.
type fakeServer struct {
	holding, input map[uint16]uint16
	mu             sync.Mutex
	fns            []byte // fungsi yang pernah diterima
	port           int
}

func newFake(t *testing.T) *fakeServer {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	f := &fakeServer{holding: map[uint16]uint16{}, input: map[uint16]uint16{}, port: ln.Addr().(*net.TCPAddr).Port}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go f.serve(c)
		}
	}()
	return f
}

func (f *fakeServer) serve(c net.Conn) {
	defer c.Close()
	for {
		hdr := make([]byte, 7)
		if _, err := io.ReadFull(c, hdr); err != nil {
			return
		}
		pdu := make([]byte, int(binary.BigEndian.Uint16(hdr[4:]))-1)
		if _, err := io.ReadFull(c, pdu); err != nil {
			return
		}
		f.mu.Lock()
		f.fns = append(f.fns, pdu[0])
		f.mu.Unlock()
		var resp []byte
		regs := map[byte]map[uint16]uint16{3: f.holding, 4: f.input}[pdu[0]]
		if regs == nil {
			resp = []byte{pdu[0] | 0x80, 1}
		} else {
			addr, qty := binary.BigEndian.Uint16(pdu[1:]), binary.BigEndian.Uint16(pdu[3:])
			resp = []byte{pdu[0], byte(qty * 2)}
			for i := uint16(0); i < qty; i++ {
				v, ok := regs[addr+i]
				if !ok {
					resp = []byte{pdu[0] | 0x80, 2}
					break
				}
				resp = binary.BigEndian.AppendUint16(resp, v)
			}
		}
		out := make([]byte, 7, 7+len(resp))
		copy(out, hdr[:4])
		binary.BigEndian.PutUint16(out[4:], uint16(len(resp)+1))
		out[6] = hdr[6]
		if _, err := c.Write(append(out, resp...)); err != nil {
			return
		}
	}
}
