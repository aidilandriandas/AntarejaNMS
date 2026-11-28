// Package sitecollector: collector kecil yang dipasang di site tambang. Ia mengukur perangkat lokal (ping & SNMP)
// lalu mengirim hasil mentahnya ke pusat lewat HTTPS web. Penilaian status, kejadian, dan penyimpanan tetap di pusat.
// Bila jalur ke pusat putus, hasil diantrekan di disk site dan dikirim berurutan saat jalur pulih, jadi riwayat
// tidak bolong dan pusat tidak menandai perangkat site down hanya karena jalurnya putus.
package sitecollector

import (
	"encoding/json"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/checks"
	"github.com/aidilandriandas/antarejanms/collector/internal/modbus"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/promx"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// Batch satu kiriman dari site ke pusat. Kiriman tanpa sampel tetap dikirim sebagai detak.
type Batch struct {
	SentAt    time.Time           `json:"sent_at"` // jam site saat dikirim, untuk mengoreksi selisih jam
	Version   string              `json:"version"`
	Queued    int                 `json:"queued"` // berkas antrean yang masih menunggu di site
	Pings     []poller.PingSample `json:"pings,omitempty"`
	SNMP      []poller.SNMPSample `json:"snmp,omitempty"`
	Checks    []CheckSample       `json:"checks,omitempty"`
	Modbus    []ModbusSample      `json:"modbus,omitempty"`
	Exporters []ExporterSample    `json:"exporters,omitempty"`
}

// ExporterSample hasil scrape exporter Prometheus satu perangkat di site.
type ExporterSample struct {
	DeviceID int64           `json:"device_id"`
	At       time.Time       `json:"at"`
	Readings []promx.Reading `json:"readings,omitempty"`
	Error    string          `json:"error,omitempty"`
}

// ExporterConfig perangkat ber-exporter yang di-scrape site.
type ExporterConfig struct {
	DeviceID int64    `json:"device_id"`
	URL      string   `json:"url"`
	Services []string `json:"services,omitempty"`
}

// CheckSample hasil satu cek layanan di site.
type CheckSample struct {
	CheckID int64         `json:"check_id"`
	At      time.Time     `json:"at"`
	Result  checks.Result `json:"result"`
}

// ModbusSample hasil baca Modbus satu perangkat di site.
type ModbusSample struct {
	DeviceID int64            `json:"device_id"`
	At       time.Time        `json:"at"`
	Readings []modbus.Reading `json:"readings,omitempty"`
	Error    string           `json:"error,omitempty"` // koneksi gagal
}

// CheckConfig cek layanan yang dijalankan site.
type CheckConfig struct {
	ID           int64  `json:"id"`
	Type         string `json:"type"`
	Host         string `json:"host"`
	URL          string `json:"url,omitempty"`
	Port         int    `json:"port,omitempty"`
	Query        string `json:"query,omitempty"`
	ExpectStatus string `json:"expect_status,omitempty"`
	ExpectText   string `json:"expect_text,omitempty"`
	ExpectAnswer string `json:"expect_answer,omitempty"`
	VerifyTLS    bool   `json:"verify_tls"`
	TimeoutS     int    `json:"timeout_s"`
	IntervalS    int    `json:"interval_s"`
}

func (c CheckConfig) spec() checks.Spec {
	return checks.Spec{ID: c.ID, Type: c.Type, Host: c.Host, URL: c.URL, Port: c.Port, Query: c.Query, ExpectStatus: c.ExpectStatus,
		ExpectText: c.ExpectText, ExpectAnswer: c.ExpectAnswer, VerifyTLS: c.VerifyTLS, Timeout: time.Duration(c.TimeoutS) * time.Second}
}

// ModbusConfig perangkat ber-Modbus yang dibaca site.
type ModbusConfig struct {
	DeviceID int64          `json:"device_id"`
	Host     string         `json:"host"`
	Port     int            `json:"port"`
	Unit     int            `json:"unit"`
	Points   []modbus.Point `json:"points"`
}

// Config jawaban pusat untuk GET konfigurasi: daftar perangkat yang dipantau collector site ini.
type Config struct {
	Collector struct {
		ID   int64  `json:"id"`
		Name string `json:"name"`
	} `json:"collector"`
	Devices   []DeviceConfig   `json:"devices"`
	Checks    []CheckConfig    `json:"checks,omitempty"`
	Modbus    []ModbusConfig   `json:"modbus,omitempty"`
	Exporters []ExporterConfig `json:"exporters,omitempty"`
}

// DeviceConfig perangkat yang harus diukur. Kredensial hanya disimpan di memori site, tidak pernah di disk.
type DeviceConfig struct {
	ID          int64             `json:"id"`
	Name        string            `json:"name"`
	Host        string            `json:"host"`
	Category    string            `json:"category"`
	PingEnabled bool              `json:"ping_enabled"`
	SNMPEnabled bool              `json:"snmp_enabled"`
	Thresholds  json.RawMessage   `json:"thresholds,omitempty"`
	SNMP        *Credentials      `json:"snmp,omitempty"`
	CustomOIDs  []store.CustomOID `json:"custom_oids,omitempty"`
}

type Credentials struct {
	Version   string `json:"version"`
	Port      int    `json:"port"`
	Community string `json:"community,omitempty"`
	User      string `json:"user,omitempty"`
	Level     string `json:"level,omitempty"`
	AuthProto string `json:"auth_proto,omitempty"`
	AuthPass  string `json:"auth_pass,omitempty"`
	PrivProto string `json:"priv_proto,omitempty"`
	PrivPass  string `json:"priv_pass,omitempty"`
}

func (c *Credentials) snmp() snmp.Credentials {
	return snmp.Credentials{Version: c.Version, Port: c.Port, Community: c.Community, User: c.User, Level: c.Level,
		AuthProto: c.AuthProto, AuthPass: c.AuthPass, PrivProto: c.PrivProto, PrivPass: c.PrivPass}
}

// device bentuk store.Device secukupnya untuk pengukuran di site.
func (d DeviceConfig) device() store.Device {
	return store.Device{ID: d.ID, Name: d.Name, Host: d.Host, Category: d.Category, PingEnabled: d.PingEnabled,
		SNMPEnabled: d.SNMPEnabled, ThresholdsJSON: d.Thresholds, CustomOIDs: d.CustomOIDs}
}

// withoutSecrets salinan daftar perangkat untuk disimpan di disk site.
func withoutSecrets(devs []DeviceConfig) []DeviceConfig {
	out := make([]DeviceConfig, len(devs))
	for i, d := range devs {
		d.SNMP = nil
		out[i] = d
	}
	return out
}
