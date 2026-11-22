// Package notify mengirim notifikasi problem ke Telegram, email (SMTP), dan webhook.
//
// Aturan per kanal: hanya problem dengan severity ≥ min_severity, tidak ditekan (maintenance/induk down),
// dan (bila kanal dibatasi) dari site tertentu. delay_minutes > 0 menjadikan kanal sebagai eskalasi:
// dikirim hanya bila problem masih terbuka DAN belum di-ACK setelah selang itu.
// Bila banyak problem muncul sekaligus (mis. listrik site padam), dikirim satu pesan ringkasan.
package notify

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/secret"
)

const (
	summaryAt  = 5               // ≥ sekian problem baru untuk satu kanal dalam satu putaran → satu pesan ringkasan
	retryAfter = 5 * time.Minute // jeda setelah kegagalan kirim
)

type Channel struct {
	ID            int64
	Name, Type    string
	Config        map[string]string
	MinSeverity   string
	DelayMinutes  int
	NotifyResolve bool
	SiteID        int64
}

// Problem adalah data problem yang dikirim.
type Problem struct {
	ID         int64      `json:"id"`
	Severity   string     `json:"severity"`
	Title      string     `json:"title"`
	Detail     string     `json:"detail"`
	Device     string     `json:"device"`
	Host       string     `json:"host"`
	Site       string     `json:"site"`
	StartedAt  time.Time  `json:"started_at"`
	ResolvedAt *time.Time `json:"resolved_at,omitempty"`
	AckedBy    string     `json:"acked_by,omitempty"`
}

// Message adalah satu pesan siap kirim.
type Message struct {
	Event   string // problem.open, problem.resolve, summary, test
	Subject string // untuk email
	Text    string // teks polos
	HTML    string // untuk Telegram (subset HTML)
	// EmailHTML: bila diisi, email dikirim multipart (teks + HTML), mis. laporan terjadwal
	EmailHTML string
	Items     []Problem // problem yang dibahas
}

type Sender interface {
	Send(ctx context.Context, cfg map[string]string, m Message) error
}

type Dispatcher struct {
	DB      *pgxpool.Pool
	Box     *secret.Box
	Log     *slog.Logger
	Senders map[string]Sender
	BaseURL string // tautan ke web (opsional)
}

func NewDispatcher(db *pgxpool.Pool, box *secret.Box, log *slog.Logger, baseURL string) *Dispatcher {
	return &Dispatcher{DB: db, Box: box, Log: log, BaseURL: strings.TrimRight(baseURL, "/"),
		Senders: map[string]Sender{"telegram": Telegram{}, "email": Email{}, "webhook": Webhook{}}}
}

func (d *Dispatcher) channels(ctx context.Context, onlyID int64) ([]Channel, error) {
	q := `SELECT id, name, type, config_enc, min_severity, delay_minutes, notify_resolve, COALESCE(site_id, 0) FROM notify_channels WHERE enabled`
	args := []any{}
	if onlyID > 0 {
		q = `SELECT id, name, type, config_enc, min_severity, delay_minutes, notify_resolve, COALESCE(site_id, 0) FROM notify_channels WHERE id = $1`
		args = append(args, onlyID)
	}
	rows, err := d.DB.Query(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Channel
	for rows.Next() {
		var c Channel
		var enc string
		if err := rows.Scan(&c.ID, &c.Name, &c.Type, &enc, &c.MinSeverity, &c.DelayMinutes, &c.NotifyResolve, &c.SiteID); err != nil {
			return nil, err
		}
		plain, err := d.Box.Decrypt(enc)
		if err != nil {
			d.Log.Error("konfigurasi kanal tidak bisa dibuka", "kanal", c.ID, "galat", err)
			continue
		}
		if err := json.Unmarshal([]byte(plain), &c.Config); err != nil {
			d.Log.Error("konfigurasi kanal rusak", "kanal", c.ID)
			continue
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

const problemCols = `p.id, p.severity, p.title, COALESCE(p.suppressed_note, p.detail, ''), d.name, d.host, COALESCE(s.code, ''), p.started_at, p.resolved_at, COALESCE(u.username, '')`

func scanProblems(rows interface {
	Next() bool
	Scan(...any) error
	Close()
	Err() error
}) ([]Problem, error) {
	defer rows.Close()
	var out []Problem
	for rows.Next() {
		var p Problem
		if err := rows.Scan(&p.ID, &p.Severity, &p.Title, &p.Detail, &p.Device, &p.Host, &p.Site, &p.StartedAt, &p.ResolvedAt, &p.AckedBy); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

// Run memproses semua kanal aktif sekali.
func (d *Dispatcher) Run(ctx context.Context) error {
	chs, err := d.channels(ctx, 0)
	if err != nil {
		return err
	}
	for _, c := range chs {
		if err := d.runChannel(ctx, c); err != nil {
			d.Log.Warn("notifikasi gagal", "kanal", c.Name, "galat", err)
		}
	}
	return nil
}

func (d *Dispatcher) runChannel(ctx context.Context, c Channel) error {
	var recentFail bool
	if err := d.DB.QueryRow(ctx, `SELECT EXISTS (SELECT 1 FROM notifications WHERE channel_id = $1 AND status = 'failed' AND at > now() - $2::interval)`,
		c.ID, fmt.Sprintf("%d seconds", int(retryAfter.Seconds()))).Scan(&recentFail); err != nil {
		return err
	}
	if recentFail {
		return nil
	}
	sevs := severitiesAtLeast(c.MinSeverity)
	siteFilter := `($4 = 0 OR d.site_id = $4)`

	rows, err := d.DB.Query(ctx, `SELECT `+problemCols+` FROM problems p JOIN devices d ON d.id = p.device_id
		LEFT JOIN sites s ON s.id = d.site_id LEFT JOIN users u ON u.id = p.acked_by
		WHERE p.resolved_at IS NULL AND p.suppressed IS NULL AND p.severity = ANY($2) AND `+siteFilter+`
		  AND p.started_at <= now() - make_interval(mins => $3) AND ($3 = 0 OR p.acked_at IS NULL)
		  AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.problem_id = p.id AND n.channel_id = $1 AND n.kind = 'open' AND n.status = 'sent')
		ORDER BY p.started_at LIMIT 200`, c.ID, sevs, c.DelayMinutes, c.SiteID)
	if err != nil {
		return err
	}
	opens, err := scanProblems(rows)
	if err != nil {
		return err
	}
	var resolves []Problem
	if c.NotifyResolve {
		rows, err := d.DB.Query(ctx, `SELECT `+problemCols+` FROM problems p JOIN devices d ON d.id = p.device_id
			LEFT JOIN sites s ON s.id = d.site_id LEFT JOIN users u ON u.id = p.acked_by
			WHERE p.resolved_at IS NOT NULL AND p.resolved_at > now() - interval '1 day'
			  AND EXISTS (SELECT 1 FROM notifications n WHERE n.problem_id = p.id AND n.channel_id = $1 AND n.kind = 'open' AND n.status = 'sent')
			  AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.problem_id = p.id AND n.channel_id = $1 AND n.kind = 'resolve' AND n.status = 'sent')
			ORDER BY p.resolved_at LIMIT 200`, c.ID)
		if err != nil {
			return err
		}
		if resolves, err = scanProblems(rows); err != nil {
			return err
		}
	}
	if err := d.sendBatch(ctx, c, "open", opens); err != nil {
		return err
	}
	return d.sendBatch(ctx, c, "resolve", resolves)
}

func (d *Dispatcher) sendBatch(ctx context.Context, c Channel, kind string, ps []Problem) error {
	if len(ps) == 0 {
		return nil
	}
	var msgs []Message
	var groups [][]Problem
	if len(ps) >= summaryAt {
		msgs = append(msgs, Summary(kind, ps, c.DelayMinutes, d.BaseURL))
		groups = append(groups, ps)
	} else {
		for _, p := range ps {
			msgs = append(msgs, Single(kind, p, c.DelayMinutes, d.BaseURL))
			groups = append(groups, []Problem{p})
		}
	}
	for i, m := range msgs {
		err := d.send(ctx, c, m)
		status, errText := "sent", (*string)(nil)
		if err != nil {
			status = "failed"
			e := truncate(err.Error(), 480)
			errText = &e
		}
		for _, p := range groups[i] {
			if _, dbErr := d.DB.Exec(ctx, `INSERT INTO notifications (problem_id, channel_id, kind, status, error) VALUES ($1,$2,$3,$4,$5) ON CONFLICT DO NOTHING`,
				p.ID, c.ID, kind, status, errText); dbErr != nil {
				return dbErr
			}
		}
		if err != nil {
			return err
		}
		d.Log.Info("notifikasi terkirim", "kanal", c.Name, "jenis", kind, "problem", len(groups[i]))
	}
	return nil
}

func (d *Dispatcher) send(ctx context.Context, c Channel, m Message) error {
	s, ok := d.Senders[c.Type]
	if !ok {
		return fmt.Errorf("jenis kanal tidak dikenal: %s", c.Type)
	}
	sctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	return s.Send(sctx, c.Config, m)
}

// SendTo mengirim pesan ke satu kanal (dipakai laporan terjadwal; kanal nonaktif tetap dikirimi bila dipilih jadwal).
func (d *Dispatcher) SendTo(ctx context.Context, channelID int64, m Message) error {
	chs, err := d.channels(ctx, channelID)
	if err != nil {
		return err
	}
	if len(chs) == 0 {
		return fmt.Errorf("kanal tidak ditemukan atau konfigurasinya tidak bisa dibuka")
	}
	return d.send(ctx, chs[0], m)
}

// Test mengirim pesan uji ke satu kanal dan mencatat hasilnya.
func (d *Dispatcher) Test(ctx context.Context, channelID int64) error {
	chs, err := d.channels(ctx, channelID)
	if err != nil {
		return err
	}
	if len(chs) == 0 {
		return fmt.Errorf("kanal tidak ditemukan atau konfigurasinya tidak bisa dibuka")
	}
	c := chs[0]
	now := time.Now().Format("02/01/2006 15.04")
	m := Message{Event: "test", Subject: "[ANTAREJA] Uji notifikasi",
		Text: "ANTAREJA NMS: uji notifikasi kanal \"" + c.Name + "\" berhasil (" + now + ").",
		HTML: "✅ <b>ANTAREJA NMS</b>\nUji notifikasi kanal <b>" + esc(c.Name) + "</b> berhasil.\n<i>" + now + "</i>"}
	err = d.send(ctx, c, m)
	status, errText := "sent", (*string)(nil)
	if err != nil {
		status = "failed"
		e := truncate(err.Error(), 480)
		errText = &e
	}
	_, _ = d.DB.Exec(ctx, `INSERT INTO notifications (channel_id, kind, status, error) VALUES ($1, 'test', $2, $3)`, c.ID, status, errText)
	return err
}

func severitiesAtLeast(min string) []string {
	order := []string{"info", "warning", "average", "high", "disaster"}
	for i, s := range order {
		if s == min {
			return order[i:]
		}
	}
	return order[2:]
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
