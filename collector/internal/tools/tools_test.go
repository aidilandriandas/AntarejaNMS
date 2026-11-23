package tools

import (
	"context"
	"net"
	"os"
	"testing"
	"time"
)

func TestCheckPort(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := ln.Addr().(*net.TCPAddr).Port
	if r := CheckPort(context.Background(), "127.0.0.1", port); r.State != "open" {
		t.Fatalf("%+v", r)
	}
	ln.Close()
	if r := CheckPort(context.Background(), "127.0.0.1", port); r.State != "closed" {
		t.Fatalf("port tertutup: %+v", r)
	}
}

// Traceroute ke localhost: butuh ping_group_range mengizinkan grup proses ini; dilewati bila tidak.
func TestTracerouteLokal(t *testing.T) {
	if os.Getenv("ANTAREJA_TEST_ICMP") == "" {
		t.Skip("set ANTAREJA_TEST_ICMP=1 untuk menguji soket ICMP")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	r := Traceroute(ctx, "127.0.0.1", 5)
	if r.Error != "" || !r.Reached || len(r.Hops) != 1 || r.Hops[0].Address != "127.0.0.1" {
		t.Fatalf("%+v", r)
	}
}

// Hop di tengah jalan (router menjawab time exceeded): set ANTAREJA_TEST_TRACE=<alamat di luar subnet>.
func TestTracerouteHop(t *testing.T) {
	target := os.Getenv("ANTAREJA_TEST_TRACE")
	if target == "" {
		t.Skip("set ANTAREJA_TEST_TRACE")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	r := Traceroute(ctx, target, 3)
	t.Logf("%+v", r)
	if r.Error != "" || len(r.Hops) == 0 || r.Hops[0].Address == "" || r.Hops[0].MS == nil {
		t.Fatalf("hop pertama harus menjawab: %+v", r)
	}
}
