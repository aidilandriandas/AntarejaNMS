// Package config membaca pengaturan collector dari environment variable.
// Tidak ada nilai rahasia bawaan: kunci dan URL database wajib diberikan saat menjalankan.
package config

import (
	"fmt"
	"os"
	"strconv"
	"time"
)

type Config struct {
	DatabaseURL    string
	AppKey         string // format "base64:..." (32 byte), sama dengan web
	VMURL          string // http://victoriametrics:8428 ; kosong = metrik tidak dikirim
	MigrationsDir  string
	HealthAddr     string
	APIAddr        string // API internal untuk web; kosong = mati
	PingInterval   time.Duration
	PingCount      int
	PingTimeout    time.Duration
	PingPrivileged bool
	SNMPInterval   time.Duration
	SNMPTimeout    time.Duration
	SNMPRetries    int
	Workers        int
	DownAfter      int     // putaran gagal berturut-turut sebelum dinyatakan down
	LatencyHighMS  float64 // ambang kejadian "latensi tinggi"
}

func Load() (Config, error) {
	c := Config{
		DatabaseURL:    os.Getenv("ANTAREJA_DATABASE_URL"),
		AppKey:         os.Getenv("ANTAREJA_APP_KEY"),
		VMURL:          os.Getenv("ANTAREJA_VM_URL"),
		MigrationsDir:  env("ANTAREJA_MIGRATIONS_DIR", "/app/migrations"),
		HealthAddr:     env("ANTAREJA_HEALTH_ADDR", "127.0.0.1:9273"),
		APIAddr:        os.Getenv("ANTAREJA_API_ADDR"),
		PingInterval:   dur("ANTAREJA_PING_INTERVAL", 60*time.Second),
		PingCount:      num("ANTAREJA_PING_COUNT", 3),
		PingTimeout:    dur("ANTAREJA_PING_TIMEOUT", 2*time.Second),
		PingPrivileged: env("ANTAREJA_PING_PRIVILEGED", "false") == "true",
		SNMPInterval:   dur("ANTAREJA_SNMP_INTERVAL", 5*time.Minute),
		SNMPTimeout:    dur("ANTAREJA_SNMP_TIMEOUT", 3*time.Second),
		SNMPRetries:    num("ANTAREJA_SNMP_RETRIES", 1),
		Workers:        num("ANTAREJA_WORKERS", 256),
		DownAfter:      num("ANTAREJA_DOWN_AFTER", 2),
		LatencyHighMS:  float64(num("ANTAREJA_LATENCY_HIGH_MS", 150)),
	}
	if c.DatabaseURL == "" {
		return c, fmt.Errorf("ANTAREJA_DATABASE_URL belum diisi")
	}
	if c.AppKey == "" {
		return c, fmt.Errorf("ANTAREJA_APP_KEY belum diisi (buat dengan: echo base64:$(openssl rand -base64 32))")
	}
	if c.PingCount < 1 || c.Workers < 1 || c.DownAfter < 1 {
		return c, fmt.Errorf("ANTAREJA_PING_COUNT, ANTAREJA_WORKERS dan ANTAREJA_DOWN_AFTER minimal 1")
	}
	return c, nil
}

func env(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func num(k string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(k)); err == nil {
		return v
	}
	return def
}

func dur(k string, def time.Duration) time.Duration {
	if v, err := time.ParseDuration(os.Getenv(k)); err == nil && v > 0 {
		return v
	}
	return def
}
