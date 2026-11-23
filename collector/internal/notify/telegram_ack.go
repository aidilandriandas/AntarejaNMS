package notify

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
)

// Tombol ACK di pesan Telegram. Collector membaca tekanan tombol lewat getUpdates (long polling dari dalam
// jaringan, tidak perlu alamat publik). Yang boleh ACK hanya pengguna ANTAREJA aktif berperan engineer/admin
// yang chat ID Telegram-nya terisi di Profil; ID pengirim diambil dari Telegram, bukan dari isi tombol.

const ackPrefix = "ack:"

// AckKeyboard: tombol ACK untuk pesan satu problem baru, bila kanal mengaktifkan ack_button.
func AckKeyboard(cfg map[string]string, m Message) map[string]any {
	if cfg["ack_button"] != "1" || m.Event != "problem.open" || len(m.Items) != 1 || m.Items[0].AckedBy != "" {
		return nil
	}
	return keyboard("ACK, saya tangani", ackPrefix+strconv.FormatInt(m.Items[0].ID, 10))
}

func keyboard(text, data string) map[string]any {
	return map[string]any{"inline_keyboard": [][]map[string]string{{{"text": text, "callback_data": data}}}}
}

// ParseAck mengambil ID problem dari data tombol.
func ParseAck(data string) (int64, bool) {
	if !strings.HasPrefix(data, ackPrefix) {
		return 0, false
	}
	id, err := strconv.ParseInt(strings.TrimPrefix(data, ackPrefix), 10, 64)
	return id, err == nil && id > 0
}

// AckBot membaca tekanan tombol dari semua bot kanal Telegram yang mengaktifkan tombol ACK.
type AckBot struct {
	Disp    *Dispatcher
	API     string // kosong = https://api.telegram.org
	Log     *slog.Logger
	offsets map[string]int64
}

type tgUpdate struct {
	UpdateID int64 `json:"update_id"`
	Callback *struct {
		ID   string `json:"id"`
		From struct {
			ID int64 `json:"id"`
		} `json:"from"`
		Message *struct {
			MessageID int64 `json:"message_id"`
			Chat      struct {
				ID int64 `json:"id"`
			} `json:"chat"`
		} `json:"message"`
		Data string `json:"data"`
	} `json:"callback_query"`
}

// Run memeriksa tiap 3 detik.
func (b *AckBot) Run(ctx context.Context) {
	t := time.NewTicker(3 * time.Second)
	defer t.Stop()
	for {
		b.Once(ctx)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// Once satu putaran untuk semua bot.
func (b *AckBot) Once(ctx context.Context) {
	if b.offsets == nil {
		b.offsets = map[string]int64{}
	}
	chs, err := b.Disp.rawChannels(ctx, 0)
	if err != nil {
		return
	}
	seen := map[string]bool{}
	for _, c := range chs {
		tok := strings.TrimSpace(c.Config["bot_token"])
		if c.Type != "telegram" || c.Config["ack_button"] != "1" || tok == "" || seen[tok] {
			continue
		}
		seen[tok] = true
		if err := b.poll(ctx, tok); err != nil && ctx.Err() == nil {
			b.Log.Warn("baca tombol Telegram gagal", "kanal", c.Name, "galat", strings.ReplaceAll(err.Error(), tok, "***"))
		}
	}
}

func (b *AckBot) call(ctx context.Context, tok, method string, in, out any) error {
	base := b.API
	if base == "" {
		base = "https://api.telegram.org"
	}
	body, _ := json.Marshal(in)
	rctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(rctx, http.MethodPost, base+"/bot"+tok+"/"+method, bytes.NewReader(body))
	if err != nil {
		return errors.New("token bot tidak valid")
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	var r struct {
		OK          bool            `json:"ok"`
		Description string          `json:"description"`
		Result      json.RawMessage `json:"result"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&r); err != nil {
		return fmt.Errorf("%s: jawaban tidak terbaca (HTTP %d)", method, resp.StatusCode)
	}
	if !r.OK {
		return fmt.Errorf("%s: %s", method, r.Description)
	}
	if out != nil {
		return json.Unmarshal(r.Result, out)
	}
	return nil
}

func (b *AckBot) poll(ctx context.Context, tok string) error {
	var ups []tgUpdate
	in := map[string]any{"timeout": 0, "allowed_updates": []string{"callback_query"}}
	if off := b.offsets[tok]; off > 0 {
		in["offset"] = off
	}
	if err := b.call(ctx, tok, "getUpdates", in, &ups); err != nil {
		return err
	}
	for _, u := range ups {
		if u.UpdateID >= b.offsets[tok] {
			b.offsets[tok] = u.UpdateID + 1
		}
		if u.Callback == nil {
			continue
		}
		text, done := b.handle(ctx, u.Callback.From.ID, u.Callback.Data)
		_ = b.call(ctx, tok, "answerCallbackQuery", map[string]any{"callback_query_id": u.Callback.ID, "text": text, "show_alert": !done}, nil)
		if done && u.Callback.Message != nil {
			_ = b.call(ctx, tok, "editMessageReplyMarkup", map[string]any{"chat_id": u.Callback.Message.Chat.ID, "message_id": u.Callback.Message.MessageID,
				"reply_markup": keyboard(text, "done")}, nil)
		}
	}
	return nil
}

// handle memproses satu tekanan tombol. done = pesan diberi tanda sudah di-ACK (tombol diganti).
func (b *AckBot) handle(ctx context.Context, fromID int64, data string) (string, bool) {
	db := b.Disp.DB
	if data == "done" {
		return "Problem ini sudah di-ACK.", false
	}
	pid, ok := ParseAck(data)
	if !ok {
		return "Tombol tidak dikenal.", false
	}
	var uid int64
	var name, role string
	err := db.QueryRow(ctx, `SELECT id, name, role FROM users WHERE active AND telegram_chat_id = $1 ORDER BY id LIMIT 1`, strconv.FormatInt(fromID, 10)).Scan(&uid, &name, &role)
	if errors.Is(err, pgx.ErrNoRows) {
		return "Akun Telegram ini belum terhubung ke ANTAREJA. Isi chat ID " + strconv.FormatInt(fromID, 10) + " di Profil, lalu coba lagi.", false
	}
	if err != nil {
		return "ANTAREJA sedang tidak bisa memproses, coba lagi atau ACK lewat web.", false
	}
	if role != "admin" && role != "engineer" {
		return "Akun " + name + " hanya bisa melihat (viewer), tidak bisa ACK.", false
	}
	var title string
	err = db.QueryRow(ctx, `UPDATE problems SET acked_at = now(), acked_by = $2, ack_note = 'lewat Telegram'
		WHERE id = $1 AND resolved_at IS NULL AND acked_at IS NULL RETURNING title`, pid, uid).Scan(&title)
	if errors.Is(err, pgx.ErrNoRows) {
		var resolved bool
		var by string
		if db.QueryRow(ctx, `SELECT p.resolved_at IS NOT NULL, COALESCE(u.name, '') FROM problems p LEFT JOIN users u ON u.id = p.acked_by WHERE p.id = $1`, pid).Scan(&resolved, &by) != nil {
			return "Problem tidak ditemukan.", false
		}
		if resolved {
			return "Problem sudah pulih, tidak perlu ACK.", true
		}
		return "Sudah di-ACK oleh " + by + ".", true
	}
	if err != nil {
		return "ANTAREJA sedang tidak bisa memproses, coba lagi atau ACK lewat web.", false
	}
	_, _ = db.Exec(ctx, `INSERT INTO audit_logs (user_id, action, subject_type, subject_id, description) VALUES ($1, 'ack', 'Problem', $2, $3)`,
		uid, pid, truncate(fmt.Sprintf("ACK problem #%d: %s, lewat Telegram", pid, title), 500))
	b.Log.Info("ACK lewat Telegram", "problem", pid, "pengguna", name)
	return "ACK oleh " + name + ", " + time.Now().Format("15.04"), true
}
