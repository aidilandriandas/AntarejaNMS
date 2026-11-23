package notify

import (
	"context"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestApplyOncallEmail(t *testing.T) {
	c := Channel{Type: "email", Config: map[string]string{"to": "noc@tambang.id", "host": "smtp"}}
	orig := c.Config
	n := ApplyOncall(&c, []OncallPerson{{Name: "Budi", Email: "budi@tambang.id"}, {Name: "Sari"}, {Name: "Andi", Email: " andi@tambang.id "}})
	if n != 2 || c.Config["to"] != "budi@tambang.id,andi@tambang.id" || c.Config["host"] != "smtp" {
		t.Fatalf("n=%d cfg=%v", n, c.Config)
	}
	if orig["to"] != "noc@tambang.id" {
		t.Fatal("konfigurasi asli ikut berubah")
	}
	if len(c.Oncall) != 2 || c.Oncall[0] != "Budi" {
		t.Fatalf("oncall=%v", c.Oncall)
	}
}

func TestApplyOncallTelegram(t *testing.T) {
	c := Channel{Type: "telegram", Config: map[string]string{"chat_id": "-100", "bot_token": "x"}}
	if n := ApplyOncall(&c, []OncallPerson{{Name: "Budi"}, {Name: "Sari", Telegram: "555"}, {Name: "Andi", Telegram: "777"}}); n != 1 {
		t.Fatalf("n=%d", n)
	}
	if c.Config["chat_id"] != "555" || c.Config["bot_token"] != "x" || c.Oncall[0] != "Sari" {
		t.Fatalf("cfg=%v oncall=%v", c.Config, c.Oncall)
	}
}

func TestApplyOncallFallback(t *testing.T) {
	for _, typ := range []string{"email", "telegram", "webhook"} {
		c := Channel{Type: typ, Config: map[string]string{"to": "noc@tambang.id", "chat_id": "-100", "url": "https://x"}}
		if n := ApplyOncall(&c, []OncallPerson{{Name: "Budi"}}); n != 0 || c.Config["to"] != "noc@tambang.id" || c.Config["chat_id"] != "-100" {
			t.Fatalf("%s: n=%d cfg=%v", typ, n, c.Config)
		}
		if n := ApplyOncall(&c, nil); n != 0 {
			t.Fatalf("%s kosong: n=%d", typ, n)
		}
	}
}

func TestAckKeyboard(t *testing.T) {
	m := Message{Event: "problem.open", Items: []Problem{{ID: 42}}}
	if AckKeyboard(map[string]string{}, m) != nil {
		t.Fatal("tombol muncul padahal kanal tidak mengaktifkan")
	}
	on := map[string]string{"ack_button": "1"}
	kb := AckKeyboard(on, m)
	rows := kb["inline_keyboard"].([][]map[string]string)
	if rows[0][0]["callback_data"] != "ack:42" {
		t.Fatalf("%v", kb)
	}
	for _, x := range []Message{{Event: "problem.resolve", Items: []Problem{{ID: 1}}}, {Event: "summary", Items: []Problem{{ID: 1}, {ID: 2}}},
		{Event: "problem.open", Items: []Problem{{ID: 1, AckedBy: "budi"}}}} {
		if AckKeyboard(on, x) != nil {
			t.Fatalf("tombol muncul untuk %+v", x)
		}
	}
	if id, ok := ParseAck("ack:42"); !ok || id != 42 {
		t.Fatal("parse")
	}
	for _, s := range []string{"ack:", "ack:-1", "ack:x", "done", "ACK:1"} {
		if _, ok := ParseAck(s); ok {
			t.Fatalf("%q diterima", s)
		}
	}
}

func TestAckBotJedaSaatGagal(t *testing.T) {
	calls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.WriteHeader(http.StatusBadGateway)
	}))
	defer srv.Close()
	var logs strings.Builder
	b := &AckBot{API: srv.URL, Log: slog.New(slog.NewTextHandler(&logs, nil))}
	for i := 0; i < 5; i++ {
		b.tryPoll(context.Background(), "1:x", "Grup NOC")
	}
	if calls != 1 {
		t.Fatalf("setelah gagal harus menunggu, server dipanggil %d kali", calls)
	}
	if n := strings.Count(logs.String(), "gagal"); n != 1 {
		t.Fatalf("log gagal %d kali:\n%s", n, logs.String())
	}
	if strings.Contains(logs.String(), "1:x") {
		t.Fatal("token bocor ke log")
	}
	b.failing["1:x"] = time.Now().Add(-time.Second) // jeda habis
	b.tryPoll(context.Background(), "1:x", "Grup NOC")
	if calls != 2 {
		t.Fatalf("setelah jeda harus dicoba lagi, calls=%d", calls)
	}
}
