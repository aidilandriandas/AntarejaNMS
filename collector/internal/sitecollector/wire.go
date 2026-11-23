// Package sitecollector: collector kecil yang dipasang di site tambang. Ia mengukur perangkat lokal (ping & SNMP)
// lalu mengirim hasil mentahnya ke pusat lewat HTTPS web. Penilaian status, kejadian, dan penyimpanan tetap di pusat.
// Bila jalur ke pusat putus, hasil diantrekan di disk site dan dikirim berurutan saat jalur pulih, jadi riwayat
// tidak bolong dan pusat tidak menandai perangkat site down hanya karena jalurnya putus.
package sitecollector

import (
	"encoding/json"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// Batch satu kiriman dari site ke pusat. Kiriman tanpa sampel tetap dikirim sebagai detak.
type Batch struct {
	SentAt  time.Time           `json:"sent_at"` // jam site saat dikirim, untuk mengoreksi selisih jam
	Version string              `json:"version"`
	Queued  int                 `json:"queued"` // berkas antrean yang masih menunggu di site
	Pings   []poller.PingSample `json:"pings,omitempty"`
	SNMP    []poller.SNMPSample `json:"snmp,omitempty"`
}

// Config jawaban pusat untuk GET konfigurasi: daftar perangkat yang dipantau collector site ini.
type Config struct {
	Collector struct {
		ID   int64  `json:"id"`
		Name string `json:"name"`
	} `json:"collector"`
	Devices []DeviceConfig `json:"devices"`
}

// DeviceConfig perangkat yang harus diukur. Kredensial hanya disimpan di memori site, tidak pernah di disk.
type DeviceConfig struct {
	ID          int64           `json:"id"`
	Name        string          `json:"name"`
	Host        string          `json:"host"`
	Category    string          `json:"category"`
	PingEnabled bool            `json:"ping_enabled"`
	SNMPEnabled bool            `json:"snmp_enabled"`
	Thresholds  json.RawMessage `json:"thresholds,omitempty"`
	SNMP        *Credentials    `json:"snmp,omitempty"`
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
		SNMPEnabled: d.SNMPEnabled, ThresholdsJSON: d.Thresholds}
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
