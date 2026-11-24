package flow

import (
	"context"
	"encoding/binary"
	"io"
	"log/slog"
	"net"
	"strconv"
	"testing"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/dbtest"
)

func TestCollectorUDP(t *testing.T) {
	db := dbtest.Open(t)
	dev := dbtest.ID(t, db, `INSERT INTO devices (name, host) VALUES ('router', '127.0.0.1') RETURNING id`)
	ln, _ := net.ListenPacket("udp", "127.0.0.1:0")
	port := ln.LocalAddr().(*net.UDPAddr).Port
	ln.Close()
	c := &Collector{DB: db, Log: slog.New(slog.NewTextHandler(io.Discard, nil)), Addr: "127.0.0.1:" + strconv.Itoa(port)}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = c.Run(ctx); close(done) }()
	time.Sleep(200 * time.Millisecond)

	pkt := func(dst [4]byte, bytes uint32) []byte {
		b := make([]byte, 24+48)
		binary.BigEndian.PutUint16(b[0:], 5)
		binary.BigEndian.PutUint16(b[2:], 1)
		r := b[24:]
		copy(r[0:], []byte{192, 168, 5, 20})
		copy(r[4:], dst[:])
		binary.BigEndian.PutUint16(r[12:], 4) // masuk lewat port 4
		binary.BigEndian.PutUint32(r[16:], 3)
		binary.BigEndian.PutUint32(r[20:], bytes)
		binary.BigEndian.PutUint16(r[32:], 50000)
		binary.BigEndian.PutUint16(r[34:], 554)
		r[38] = 6
		return b
	}
	conn, err := net.Dial("udp", c.Addr)
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 3; i++ {
		_, _ = conn.Write(pkt([4]byte{10, 0, 0, 9}, 1000))
	}
	conn.Close()
	// eksportir tak dikenal (alamat sumber lain) diabaikan
	other, err := net.DialUDP("udp", &net.UDPAddr{IP: net.ParseIP("127.0.0.2")}, &net.UDPAddr{IP: net.ParseIP("127.0.0.1"), Port: port})
	if err == nil {
		_, _ = other.Write(pkt([4]byte{10, 0, 0, 8}, 999))
		other.Close()
	}
	time.Sleep(300 * time.Millisecond)
	cancel()
	<-done
	var n, bytes int64
	var port2, ifi int64
	var src string
	for i := 0; i < 20; i++ {
		if err := db.QueryRow(context.Background(), `SELECT count(*), COALESCE(sum(bytes), 0), COALESCE(max(port), 0), COALESCE(max(if_index), 0), COALESCE(max(host(src)), '')
			FROM flow_stats WHERE device_id = $1`, dev).Scan(&n, &bytes, &port2, &ifi, &src); err == nil && n > 0 {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if n != 1 || bytes != 3000 || port2 != 554 || ifi != 4 || src != "192.168.5.20" {
		t.Fatalf("satu baris ringkasan: n=%d bytes=%d port=%d if=%d src=%s", n, bytes, port2, ifi, src)
	}
	if err == nil && c.Unknown.Load() != 1 {
		t.Fatalf("eksportir tak dikenal dihitung, dapat %d", c.Unknown.Load())
	}
}
