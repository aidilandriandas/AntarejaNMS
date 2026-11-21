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
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/poller"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
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
	Token  string
	Log    *slog.Logger
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /v1/test", s.auth(s.test))
	mux.HandleFunc("POST /v1/devices/{id}/poll", s.auth(s.poll))
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
