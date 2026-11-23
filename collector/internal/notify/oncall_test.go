package notify

import "testing"

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
