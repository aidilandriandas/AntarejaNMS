package notify

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/smtp"
	"strconv"
	"strings"
	"time"
)

var httpClient = &http.Client{Timeout: 15 * time.Second}

// Telegram mengirim lewat Bot API (butuh akses HTTPS ke api.telegram.org dari server).
type Telegram struct{ BaseURL string }

func (t Telegram) Send(ctx context.Context, cfg map[string]string, m Message) error {
	token, chat := strings.TrimSpace(cfg["bot_token"]), strings.TrimSpace(cfg["chat_id"])
	if token == "" || chat == "" {
		return errors.New("token bot atau chat ID kosong")
	}
	base := t.BaseURL
	if base == "" {
		base = "https://api.telegram.org"
	}
	body, _ := json.Marshal(map[string]any{"chat_id": chat, "text": m.HTML, "parse_mode": "HTML", "disable_web_page_preview": true})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, base+"/bot"+token+"/sendMessage", bytes.NewReader(body))
	if err != nil {
		return errors.New("token bot tidak valid")
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := httpClient.Do(req)
	if err != nil {
		// jangan bocorkan token yang ada di URL
		return errors.New("tidak bisa menghubungi api.telegram.org: " + strings.ReplaceAll(err.Error(), token, "***"))
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		var r struct {
			Description string `json:"description"`
		}
		_ = json.NewDecoder(io.LimitReader(resp.Body, 4096)).Decode(&r)
		switch resp.StatusCode {
		case http.StatusUnauthorized:
			return errors.New("token bot ditolak Telegram (401)")
		case http.StatusBadRequest, http.StatusForbidden:
			return fmt.Errorf("Telegram menolak: %s — pastikan bot sudah dimasukkan ke grup dan chat ID benar", r.Description)
		}
		return fmt.Errorf("Telegram menjawab %d: %s", resp.StatusCode, r.Description)
	}
	return nil
}

// Email lewat SMTP. security: "starttls" (port 587), "tls" (port 465), "none" (relay internal).
type Email struct{}

func (Email) Send(ctx context.Context, cfg map[string]string, m Message) error {
	host, from := strings.TrimSpace(cfg["host"]), strings.TrimSpace(cfg["from"])
	var to []string
	for _, a := range strings.FieldsFunc(cfg["to"], func(r rune) bool { return r == ',' || r == ';' || r == ' ' || r == '\n' }) {
		to = append(to, strings.TrimSpace(a))
	}
	if host == "" || from == "" || len(to) == 0 {
		return errors.New("host SMTP, pengirim, atau penerima kosong")
	}
	port, _ := strconv.Atoi(cfg["port"])
	if port == 0 {
		port = 587
	}
	addr := net.JoinHostPort(host, strconv.Itoa(port))
	dl, ok := ctx.Deadline()
	if !ok {
		dl = time.Now().Add(20 * time.Second)
	}
	dialer := &net.Dialer{Deadline: dl}
	var conn net.Conn
	var err error
	if cfg["security"] == "tls" {
		conn, err = tls.DialWithDialer(dialer, "tcp", addr, &tls.Config{ServerName: host, MinVersion: tls.VersionTLS12})
	} else {
		conn, err = dialer.DialContext(ctx, "tcp", addr)
	}
	if err != nil {
		return fmt.Errorf("tidak bisa terhubung ke %s: %w", addr, err)
	}
	_ = conn.SetDeadline(dl)
	c, err := smtp.NewClient(conn, host)
	if err != nil {
		conn.Close()
		return fmt.Errorf("server SMTP tidak menjawab dengan benar: %w", err)
	}
	defer c.Close()
	if cfg["security"] == "starttls" || cfg["security"] == "" {
		if ok, _ := c.Extension("STARTTLS"); !ok {
			return errors.New("server tidak mendukung STARTTLS; pilih keamanan \"tls\" (port 465) atau \"none\" untuk relay internal")
		}
		if err := c.StartTLS(&tls.Config{ServerName: host, MinVersion: tls.VersionTLS12}); err != nil {
			return fmt.Errorf("STARTTLS gagal: %w", err)
		}
	}
	if user := cfg["username"]; user != "" {
		if err := c.Auth(smtp.PlainAuth("", user, cfg["password"], host)); err != nil {
			return fmt.Errorf("login SMTP ditolak: %w", err)
		}
	}
	if err := c.Mail(from); err != nil {
		return fmt.Errorf("alamat pengirim ditolak: %w", err)
	}
	for _, a := range to {
		if err := c.Rcpt(a); err != nil {
			return fmt.Errorf("penerima %s ditolak: %w", a, err)
		}
	}
	w, err := c.Data()
	if err != nil {
		return err
	}
	head := "From: ANTAREJA NMS <" + from + ">\r\nTo: " + strings.Join(to, ", ") + "\r\nSubject: " + mimeHeader(m.Subject) +
		"\r\nDate: " + time.Now().Format(time.RFC1123Z) + "\r\nMIME-Version: 1.0\r\n"
	text := strings.ReplaceAll(m.Text, "\n", "\r\n")
	var msg string
	if m.EmailHTML == "" {
		msg = head + "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" + text + "\r\n"
	} else {
		// multipart/alternative: klien email yang tidak menampilkan HTML tetap mendapat versi teks
		bnd := fmt.Sprintf("antareja-%d", time.Now().UnixNano())
		msg = head + "Content-Type: multipart/alternative; boundary=\"" + bnd + "\"\r\n\r\n" +
			"--" + bnd + "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" + wrap76(b64(text)) +
			"--" + bnd + "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" + wrap76(b64(m.EmailHTML)) +
			"--" + bnd + "--\r\n"
	}
	if _, err := w.Write([]byte(msg)); err != nil {
		return err
	}
	if err := w.Close(); err != nil {
		return fmt.Errorf("email ditolak server: %w", err)
	}
	return c.Quit()
}

// wrap76 memotong base64 per 76 karakter (RFC 2045).
func wrap76(s string) string {
	var b strings.Builder
	for len(s) > 76 {
		b.WriteString(s[:76] + "\r\n")
		s = s[76:]
	}
	b.WriteString(s + "\r\n")
	return b.String()
}

func mimeHeader(s string) string {
	for _, r := range s {
		if r > 127 {
			return "=?UTF-8?B?" + b64(s) + "?="
		}
	}
	return s
}

// Webhook mengirim JSON (mis. ke gateway WhatsApp, n8n, atau sistem tiket).
// Bila "secret" diisi, header X-Antareja-Signature = hex(HMAC-SHA256(secret, body)).
type Webhook struct{}

func (Webhook) Send(ctx context.Context, cfg map[string]string, m Message) error {
	url := strings.TrimSpace(cfg["url"])
	if !strings.HasPrefix(url, "https://") && !strings.HasPrefix(url, "http://") {
		return errors.New("URL webhook harus diawali http:// atau https://")
	}
	body, _ := json.Marshal(map[string]any{"event": m.Event, "subject": m.Subject, "text": m.Text, "problems": m.Items, "sent_at": time.Now().Format(time.RFC3339)})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "ANTAREJA-NMS")
	if sec := cfg["secret"]; sec != "" {
		h := hmac.New(sha256.New, []byte(sec))
		h.Write(body)
		req.Header.Set("X-Antareja-Signature", hex.EncodeToString(h.Sum(nil)))
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("webhook tidak terjangkau: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 200))
		return fmt.Errorf("webhook menjawab %d: %s", resp.StatusCode, strings.TrimSpace(string(b)))
	}
	return nil
}
