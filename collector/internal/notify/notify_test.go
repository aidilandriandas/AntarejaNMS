package notify

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

var p1 = Problem{ID: 7, Severity: "high", Title: "Core Switch Pit A down", Detail: "tidak menjawab ping", Device: "Core Switch Pit A",
	Host: "10.10.1.1", Site: "PIT-A", StartedAt: time.Date(2026, 11, 22, 3, 58, 0, 0, time.Local)}

func TestTelegram(t *testing.T) {
	var got map[string]any
	var path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path = r.URL.Path
		_ = json.NewDecoder(r.Body).Decode(&got)
		if got["chat_id"] == "bad" {
			w.WriteHeader(400)
			w.Write([]byte(`{"ok":false,"description":"Bad Request: chat not found"}`))
			return
		}
		w.Write([]byte(`{"ok":true}`))
	}))
	defer srv.Close()
	tg := Telegram{BaseURL: srv.URL}
	m := Single("open", p1, 0, "https://nms.local:8443")
	if err := tg.Send(context.Background(), map[string]string{"bot_token": "123:ABC", "chat_id": "-100"}, m); err != nil {
		t.Fatal(err)
	}
	if path != "/bot123:ABC/sendMessage" || got["parse_mode"] != "HTML" || !strings.Contains(got["text"].(string), "<b>Core Switch Pit A down</b>") {
		t.Fatalf("path=%s body=%v", path, got)
	}
	err := tg.Send(context.Background(), map[string]string{"bot_token": "123:ABC", "chat_id": "bad"}, m)
	if err == nil || !strings.Contains(err.Error(), "chat not found") {
		t.Fatalf("galat Telegram harus diteruskan: %v", err)
	}
}

func TestWebhookSignature(t *testing.T) {
	var sig string
	var body []byte
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		sig = r.Header.Get("X-Antareja-Signature")
		body, _ = io.ReadAll(r.Body)
	}))
	defer srv.Close()
	if err := (Webhook{}).Send(context.Background(), map[string]string{"url": srv.URL, "secret": "rahasia"}, Single("open", p1, 0, "")); err != nil {
		t.Fatal(err)
	}
	h := hmac.New(sha256.New, []byte("rahasia"))
	h.Write(body)
	if sig != hex.EncodeToString(h.Sum(nil)) || !strings.Contains(string(body), `"event":"problem.open"`) {
		t.Fatalf("sig=%s body=%s", sig, body)
	}
}

func TestFormat(t *testing.T) {
	esc := Single("open", p1, 30, "")
	if !strings.HasPrefix(esc.Subject, "[ANTAREJA] ESKALASI HIGH (belum di-ACK 30 menit)") {
		t.Fatal(esc.Subject)
	}
	r := p1
	end := p1.StartedAt.Add(95 * time.Minute)
	r.ResolvedAt = &end
	if m := Single("resolve", r, 0, ""); !strings.Contains(m.Text, "Lama gangguan: 1 jam 35 menit") {
		t.Fatal(m.Text)
	}
	var many []Problem
	for i := 0; i < 20; i++ {
		x := p1
		x.Site = []string{"PIT-A", "PIT-B"}[i%2]
		many = append(many, x)
	}
	s := Summary("open", many, 0, "")
	if s.Subject != "[ANTAREJA] 20 problem baru" || !strings.Contains(s.Text, "dan 5 lainnya") {
		t.Fatalf("%s\n%s", s.Subject, s.Text)
	}
	if mimeHeader("Lama gangguan ✅") == "Lama gangguan ✅" {
		t.Fatal("subjek non-ASCII harus di-encode")
	}
}
