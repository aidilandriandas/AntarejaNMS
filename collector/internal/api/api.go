// Package api adalah API internal collector untuk aplikasi web (hanya di jaringan Docker internal).
//
// Autentikasi: header "Authorization: Bearer <token>", token = hex(HMAC-SHA256(kunci aplikasi, "antareja-collector-api")).
// Web dan collector sudah berbagi ANTAREJA_APP_KEY, jadi tidak ada rahasia tambahan yang harus dikelola.
// Kredensial yang dikirim untuk uji koneksi tidak pernah disimpan, dicatat ke log, atau dikembalikan.
package api

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/notify"
	"github.com/aidilandriandas/antarejanms/collector/internal/offsite"
	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/tools"
)

const tokenLabel = "antareja-collector-api"

// Token menghitung token API dari kunci mentah 32 byte.
func Token(rawKey []byte) string {
	h := hmac.New(sha256.New, rawKey)
	h.Write([]byte(tokenLabel))
	return hex.EncodeToString(h.Sum(nil))
}

type Server struct {
	Poller *poller.Poller
	Notify *notify.Dispatcher
	// Preview laporan terjadwal (opsional)
	Report func(ctx context.Context, scheduleID int64) (subject, html string, err error)
	// Salinan backup ke luar server (opsional)
	Offsite *offsite.Runner
	Token   string
	Log     *slog.Logger
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /v1/test", s.auth(s.test))
	mux.HandleFunc("POST /v1/devices/{id}/poll", s.auth(s.poll))
	mux.HandleFunc("POST /v1/topology/sync", s.auth(s.syncTopology))
	mux.HandleFunc("GET /v1/devices/{id}/diagnose", s.auth(s.diagnose))
	mux.HandleFunc("POST /v1/devices/{id}/tools", s.auth(s.tools))
	mux.HandleFunc("POST /v1/backup/offsite/test", s.auth(s.offsiteTest))
	mux.HandleFunc("POST /v1/channels/{id}/test", s.auth(s.testChannel))
	mux.HandleFunc("POST /v1/live", s.auth(s.live))
	mux.HandleFunc("GET /v1/reports/{id}/preview", s.auth(s.reportPreview))
	return mux
}

func (s *Server) auth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if !hmac.Equal([]byte(got), []byte(s.Token)) {
			writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "token tidak valid"})
			return
		}
		next(w, r)
	}
}

type testReq struct {
	Host string `json:"host"`
	Ping bool   `json:"ping"`
	SNMP *struct {
		Version   string `json:"version"`
		Port      int    `json:"port"`
		Community string `json:"community"`
		User      string `json:"user"`
		Level     string `json:"level"`
		AuthProto string `json:"auth_proto"`
		AuthPass  string `json:"auth_pass"`
		PrivProto string `json:"priv_proto"`
		PrivPass  string `json:"priv_pass"`
	} `json:"snmp"`
}

type pingOut struct {
	OK      bool    `json:"ok"`
	Sent    int     `json:"sent"`
	Recv    int     `json:"recv"`
	AvgMS   float64 `json:"avg_ms"`
	LossPct float64 `json:"loss_pct"`
	Error   string  `json:"error,omitempty"`
}

type snmpOut struct {
	OK           bool   `json:"ok"`
	Error        string `json:"error,omitempty"`
	SysName      string `json:"sys_name,omitempty"`
	SysDescr     string `json:"sys_descr,omitempty"`
	SysObjectID  string `json:"sys_object_id,omitempty"`
	SysLocation  string `json:"sys_location,omitempty"`
	UptimeS      int64  `json:"uptime_s,omitempty"`
	Interfaces   int    `json:"interfaces"`
	InterfacesUp int    `json:"interfaces_up"`
}

func (s *Server) test(w http.ResponseWriter, r *http.Request) {
	var in testReq
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 16<<10)).Decode(&in); err != nil || !validHost(in.Host) {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "host tidak valid"})
		return
	}
	out := map[string]any{}
	if in.Ping {
		pg := s.Poller.Ping
		pg.Count = 3
		res := pg.One(r.Context(), in.Host)
		out["ping"] = pingOut{OK: res.OK(), Sent: res.Sent, Recv: res.Recv, AvgMS: res.AvgMS, LossPct: res.LossPct, Error: res.Err}
	}
	if in.SNMP != nil {
		cr := snmp.Credentials{Version: in.SNMP.Version, Port: in.SNMP.Port, Community: in.SNMP.Community, User: in.SNMP.User,
			Level: in.SNMP.Level, AuthProto: in.SNMP.AuthProto, AuthPass: in.SNMP.AuthPass, PrivProto: in.SNMP.PrivProto, PrivPass: in.SNMP.PrivPass}
		so := snmpOut{}
		if sys, err := s.Poller.SNMP.System(in.Host, cr); err != nil {
			so.Error = snmp.Friendly(err)
		} else {
			so.OK, so.SysName, so.SysDescr, so.SysObjectID, so.SysLocation, so.UptimeS = true, sys.Name, sys.Descr, sys.ObjectID, sys.Location, sys.UptimeSeconds()
			if ifs, err := s.Poller.SNMP.Interfaces(in.Host, cr); err == nil {
				so.Interfaces = len(ifs)
				for _, i := range ifs {
					if i.OperUp {
						so.InterfacesUp++
					}
				}
			}
		}
		out["snmp"] = so
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) poll(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "perangkat tidak ditemukan"})
		return
	}
	// Jalankan di latar agar web tidak menunggu; hasil terlihat di halaman perangkat beberapa detik kemudian.
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		if err := s.Poller.PollNow(ctx, id); err != nil {
			s.Log.Warn("polling segera gagal", "perangkat", id, "galat", err)
		}
	}()
	writeJSON(w, http.StatusAccepted, map[string]string{"status": "dijadwalkan"})
}

// offsiteTest menguji tujuan salinan backup: tulis, baca daftar, hapus berkas kecil.
func (s *Server) offsiteTest(w http.ResponseWriter, r *http.Request) {
	if s.Offsite == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "salinan backup tidak aktif di collector ini"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 45*time.Second)
	defer cancel()
	writeJSON(w, http.StatusOK, s.Offsite.Test(ctx))
}

// tools menjalankan alat diagnosa ke host perangkat itu (bukan sembarang alamat, supaya NMS tidak jadi alat pemindai).
func (s *Server) tools(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "perangkat tidak ditemukan"})
		return
	}
	var in struct {
		Tool string `json:"tool"`
		Port int    `json:"port"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4<<10)).Decode(&in); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "permintaan tidak valid"})
		return
	}
	d, err := s.Poller.Store.Device(r.Context(), id)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "perangkat tidak ditemukan"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 40*time.Second)
	defer cancel()
	switch in.Tool {
	case "ping":
		writeJSON(w, http.StatusOK, tools.Ping(ctx, d.Host, 10, s.Poller.Ping.Privileged))
	case "traceroute":
		writeJSON(w, http.StatusOK, tools.Traceroute(ctx, d.Host, 20))
	case "port":
		if in.Port < 1 || in.Port > 65535 {
			writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "port 1 sampai 65535"})
			return
		}
		writeJSON(w, http.StatusOK, tools.CheckPort(ctx, d.Host, in.Port))
	default:
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "alat tidak dikenal"})
	}
}

// diagnose membaca perangkat sekarang untuk berkas diagnosa SNMP (hanya baca, tanpa kredensial).
func (s *Server) diagnose(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "perangkat tidak ditemukan"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 50*time.Second)
	defer cancel()
	d, err := s.Poller.Diagnose(ctx, id)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "perangkat tidak ditemukan"})
		return
	}
	writeJSON(w, http.StatusOK, d)
}

// syncTopology menjalankan putaran SNMP penuh sekarang (LLDP/CDP dan pencocokan tetangga) di latar.
// 409 bila putaran lain masih berjalan, supaya web bisa memberi tahu tanpa menumpuk putaran.
func (s *Server) syncTopology(w http.ResponseWriter, r *http.Request) {
	if s.Poller.SNMPBusy() {
		writeJSON(w, http.StatusConflict, map[string]string{"status": "sedang berjalan"})
		return
	}
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		defer cancel()
		start := time.Now()
		if err := s.Poller.SyncNow(ctx); err != nil {
			s.Log.Warn("sinkron topologi gagal", "galat", err)
			return
		}
		s.Log.Info("sinkron topologi selesai", "durasi", time.Since(start).Round(time.Second))
	}()
	writeJSON(w, http.StatusAccepted, map[string]string{"status": "dijadwalkan"})
}

// reportPreview menyusun laporan satu jadwal tanpa mengirim (untuk pratinjau di web).
func (s *Server) reportPreview(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || s.Report == nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "jadwal tidak ditemukan"})
		return
	}
	subj, body, err := s.Report(r.Context(), id)
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "subject": subj, "html": body})
}

// testChannel mengirim pesan uji ke satu kanal notifikasi (sinkron, agar hasilnya langsung terlihat di web).
func (s *Server) testChannel(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || s.Notify == nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "kanal tidak ditemukan"})
		return
	}
	if err := s.Notify.Test(r.Context(), id); err != nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// live membaca counter oktet port saat ini (maks. 60 port) untuk trafik live; laju dihitung di browser
// dari selisih dua pembacaan. Hanya GET SNMP ke perangkat yang sudah terdaftar.
func (s *Server) live(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Ports []struct {
			DeviceID int64 `json:"device_id"`
			IfIndex  int   `json:"if_index"`
		} `json:"ports"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 64<<10)).Decode(&in); err != nil || len(in.Ports) > 60 {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "permintaan tidak valid (maks. 60 port)"})
		return
	}
	byDev := map[int64][]int{}
	for _, p := range in.Ports {
		byDev[p.DeviceID] = append(byDev[p.DeviceID], p.IfIndex)
	}
	type sample struct {
		DeviceID int64   `json:"device_id"`
		IfIndex  int     `json:"if_index"`
		In       uint64  `json:"in"`
		Out      uint64  `json:"out"`
		At       float64 `json:"at"`
	}
	var mu sync.Mutex
	var wg sync.WaitGroup
	out := []sample{}
	for dev, idx := range byDev {
		wg.Add(1)
		go func(dev int64, idx []int) {
			defer wg.Done()
			d, err := s.Poller.Store.Device(r.Context(), dev)
			if err != nil || !d.SNMPEnabled {
				return
			}
			cr, err := s.Poller.Credentials(d)
			if err != nil {
				return
			}
			c, err := s.Poller.SNMP.Counters(d.Host, cr, idx)
			if err != nil {
				return
			}
			at := float64(time.Now().UnixMilli()) / 1000
			mu.Lock()
			for i, v := range c {
				out = append(out, sample{DeviceID: dev, IfIndex: i, In: v[0], Out: v[1], At: at})
			}
			mu.Unlock()
		}(dev, idx)
	}
	wg.Wait()
	writeJSON(w, http.StatusOK, map[string]any{"samples": out})
}

// validHost menolak nilai yang jelas bukan host (mencegah API dipakai untuk hal lain).
func validHost(h string) bool {
	if h == "" || len(h) > 253 || strings.ContainsAny(h, " /\\@?#") {
		return false
	}
	if net.ParseIP(h) != nil {
		return true
	}
	for _, part := range strings.Split(h, ".") {
		if part == "" || len(part) > 63 {
			return false
		}
		for _, c := range part {
			if !(c == '-' || c == '_' || c >= '0' && c <= '9' || c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z') {
				return false
			}
		}
	}
	return true
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	json.NewEncoder(w).Encode(v) //nolint:errcheck
}
