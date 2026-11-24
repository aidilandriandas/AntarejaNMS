// Package modbus: klien Modbus TCP minimal yang HANYA membaca (fungsi 03 Read Holding Registers dan 04 Read Input
// Registers), ditulis dari spesifikasi Modbus Application Protocol v1.1b3 dan Modbus Messaging on TCP/IP v1.0b.
// Fungsi tulis sengaja tidak ada, sehingga ANTAREJA tidak bisa mengubah apa pun di perangkat lapangan.
package modbus

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"math"
	"net"
	"strconv"
	"sync/atomic"
	"time"
)

// Fungsi yang diizinkan.
const (
	ReadHolding = 3
	ReadInput   = 4
)

var exceptions = map[byte]string{1: "fungsi tidak didukung perangkat", 2: "alamat register tidak ada", 3: "nilai permintaan tidak valid",
	4: "perangkat gagal membaca", 6: "perangkat sibuk", 10: "gateway: jalur tidak tersedia", 11: "gateway: perangkat tujuan tidak menjawab"}

// Conn satu koneksi Modbus TCP ke satu perangkat.
type Conn struct {
	c       net.Conn
	unit    byte
	tid     atomic.Uint32
	Timeout time.Duration
}

// Dial membuka koneksi TCP.
func Dial(ctx context.Context, host string, port int, unit int, timeout time.Duration) (*Conn, error) {
	var d net.Dialer
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	c, err := d.DialContext(cctx, "tcp", net.JoinHostPort(host, strconv.Itoa(port)))
	if err != nil {
		return nil, err
	}
	return &Conn{c: c, unit: byte(unit), Timeout: timeout}, nil
}

func (m *Conn) Close() error { return m.c.Close() }

// Read membaca qty register (1..125) dengan fungsi 03 atau 04.
func (m *Conn) Read(fn byte, addr, qty uint16) ([]uint16, error) {
	if fn != ReadHolding && fn != ReadInput {
		return nil, errors.New("hanya fungsi baca 03/04 yang diizinkan")
	}
	if qty == 0 || qty > 125 {
		return nil, errors.New("jumlah register 1 sampai 125")
	}
	tid := uint16(m.tid.Add(1))
	req := make([]byte, 12)
	binary.BigEndian.PutUint16(req[0:], tid)
	binary.BigEndian.PutUint16(req[2:], 0) // protokol Modbus
	binary.BigEndian.PutUint16(req[4:], 6) // unit + PDU 5 byte
	req[6] = m.unit
	req[7] = fn
	binary.BigEndian.PutUint16(req[8:], addr)
	binary.BigEndian.PutUint16(req[10:], qty)
	_ = m.c.SetDeadline(time.Now().Add(m.Timeout))
	if _, err := m.c.Write(req); err != nil {
		return nil, err
	}
	hdr := make([]byte, 7)
	if _, err := io.ReadFull(m.c, hdr); err != nil {
		return nil, err
	}
	n := int(binary.BigEndian.Uint16(hdr[4:])) - 1
	if binary.BigEndian.Uint16(hdr[0:]) != tid || binary.BigEndian.Uint16(hdr[2:]) != 0 || n < 2 || n > 253 {
		return nil, errors.New("jawaban Modbus tidak valid")
	}
	pdu := make([]byte, n)
	if _, err := io.ReadFull(m.c, pdu); err != nil {
		return nil, err
	}
	if pdu[0] == fn|0x80 {
		if msg, ok := exceptions[pdu[1]]; ok {
			return nil, fmt.Errorf("pengecualian Modbus %d: %s", pdu[1], msg)
		}
		return nil, fmt.Errorf("pengecualian Modbus %d", pdu[1])
	}
	if pdu[0] != fn || int(pdu[1]) != int(qty)*2 || len(pdu) != 2+int(qty)*2 {
		return nil, errors.New("jawaban Modbus tidak sesuai permintaan")
	}
	out := make([]uint16, qty)
	for i := range out {
		out[i] = binary.BigEndian.Uint16(pdu[2+2*i:])
	}
	return out, nil
}

// Words jumlah register untuk tipe data.
func Words(dtype string) uint16 {
	switch dtype {
	case "u32", "s32", "f32":
		return 2
	}
	return 1
}

// Decode mengubah register menjadi angka. swap: untuk 32-bit, register pertama berisi word rendah.
func Decode(regs []uint16, dtype string, swap bool) (float64, error) {
	if len(regs) < int(Words(dtype)) {
		return 0, errors.New("register kurang")
	}
	var v32 uint32
	if Words(dtype) == 2 {
		hi, lo := regs[0], regs[1]
		if swap {
			hi, lo = lo, hi
		}
		v32 = uint32(hi)<<16 | uint32(lo)
	}
	switch dtype {
	case "u16":
		return float64(regs[0]), nil
	case "s16":
		return float64(int16(regs[0])), nil
	case "u32":
		return float64(v32), nil
	case "s32":
		return float64(int32(v32)), nil
	case "f32":
		f := float64(math.Float32frombits(v32))
		if math.IsNaN(f) || math.IsInf(f, 0) {
			return 0, errors.New("nilai float tidak valid")
		}
		return f, nil
	}
	return 0, fmt.Errorf("tipe data %q tidak dikenal", dtype)
}
