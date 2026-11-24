package syslog

import (
	"context"
	"regexp"
	"strings"
	"sync"
	"time"
)

// Rule aturan pola log. Regex memakai sintaks RE2 (Go): waktu cocok linear, tidak bisa dibuat lambat oleh pola.
type Rule struct {
	ID          int64
	Name        string
	Pattern     string
	Regex       bool
	MaxSeverity int
	App         string
	SiteID      *int64
	DeviceID    *int64
	MinCount    int
	Window      time.Duration

	re    *regexp.Regexp
	lower string
}

// Compile menyiapkan pencocokan; galat bila regex tidak sah (aturan dilewati).
func (r *Rule) Compile() error {
	if r.Regex {
		re, err := regexp.Compile("(?i)" + r.Pattern)
		if err != nil {
			return err
		}
		r.re = re
		return nil
	}
	r.lower = strings.ToLower(r.Pattern)
	return nil
}

// Match: tingkat, program, cakupan, lalu teks (tanpa beda huruf besar/kecil).
func (r *Rule) Match(m Message, dev int64, site *int64) bool {
	if m.Severity > r.MaxSeverity {
		return false
	}
	if r.App != "" && !strings.EqualFold(r.App, m.App) {
		return false
	}
	if r.DeviceID != nil && *r.DeviceID != dev {
		return false
	}
	if r.SiteID != nil && (site == nil || *site != *r.SiteID) {
		return false
	}
	if r.re != nil {
		return r.re.MatchString(m.Text)
	}
	return r.lower != "" && strings.Contains(strings.ToLower(m.Text), r.lower)
}

// Counter jendela geser per (aturan, perangkat). Hanya menyimpan waktu kemunculan dalam jendela (maks. MinCount).
type Counter struct {
	mu   sync.Mutex
	hits map[[2]int64][]time.Time
	sent map[[2]int64]time.Time // terakhir ditulis ke database, untuk membatasi tulis saat log deras
}

// Add mencatat satu kecocokan. fire = jumlah dalam jendela mencapai MinCount; n = jumlah itu.
func (c *Counter) Add(r *Rule, dev int64, at time.Time) (fire bool, n int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.hits == nil {
		c.hits, c.sent = map[[2]int64][]time.Time{}, map[[2]int64]time.Time{}
	}
	k := [2]int64{r.ID, dev}
	list := c.hits[k]
	cut := at.Add(-r.Window)
	i := 0
	for i < len(list) && !list[i].After(cut) {
		i++
	}
	list = append(list[i:], at)
	if len(list) > r.MinCount {
		list = list[len(list)-r.MinCount:]
	}
	c.hits[k] = list
	if len(list) < r.MinCount {
		return false, len(list)
	}
	// sudah terpicu: tulis ulang paling sering tiap 10 detik
	if t, ok := c.sent[k]; ok && at.Sub(t) < 10*time.Second {
		return false, len(list)
	}
	c.sent[k] = at
	return true, len(list)
}

// Prune membuang kunci yang tidak cocok lagi lebih lama dari jendelanya.
func (c *Counter) Prune(now time.Time, maxWindow time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for k, l := range c.hits {
		if len(l) == 0 || now.Sub(l[len(l)-1]) > maxWindow {
			delete(c.hits, k)
			delete(c.sent, k)
		}
	}
}

func (s *Server) loadRules(ctx context.Context) {
	rows, err := s.DB.Query(ctx, `SELECT id, name, pattern, is_regex, max_severity, COALESCE(app, ''), site_id, device_id, min_count, window_s
		FROM syslog_rules WHERE enabled ORDER BY id`)
	if err != nil {
		s.Log.Warn("syslog: gagal membaca aturan pola", "galat", err)
		return
	}
	defer rows.Close()
	var out []*Rule
	for rows.Next() {
		r := &Rule{}
		var win int
		if rows.Scan(&r.ID, &r.Name, &r.Pattern, &r.Regex, &r.MaxSeverity, &r.App, &r.SiteID, &r.DeviceID, &r.MinCount, &win) != nil {
			continue
		}
		r.Window = time.Duration(win) * time.Second
		if err := r.Compile(); err != nil {
			s.Log.Warn("syslog: pola aturan tidak sah, dilewati", "aturan", r.Name, "galat", err)
			continue
		}
		out = append(out, r)
	}
	s.rules.Store(&out)
}

type ruleHit struct {
	rule, dev int64
	n         int
	at        time.Time
	msg       string
}

// applyRules mencocokkan satu batch dan menulis aturan yang terpicu.
func (s *Server) applyRules(ctx context.Context, batch []Message) {
	rp := s.rules.Load()
	if rp == nil || len(*rp) == 0 {
		return
	}
	sites := s.sites.Load()
	var fired []ruleHit
	for _, m := range batch {
		a, dev := s.deviceFor(m.Source)
		if !a.IsValid() || dev == nil {
			continue
		}
		var site *int64
		if sites != nil {
			if v, ok := (*sites)[*dev]; ok {
				site = &v
			}
		}
		for _, r := range *rp {
			if !r.Match(m, *dev, site) {
				continue
			}
			if fire, n := s.counter.Add(r, *dev, m.Received); fire {
				msg := m.Text
				if len(msg) > 480 {
					msg = strings.ToValidUTF8(msg[:480], "")
				}
				fired = append(fired, ruleHit{r.ID, *dev, n, m.Received, msg})
			}
		}
	}
	for _, h := range fired {
		if _, err := s.DB.Exec(ctx, `INSERT INTO syslog_rule_hits (rule_id, device_id, fired_at, last_match_at, hits, last_message)
			VALUES ($1, $2, $3, $3, $4, $5)
			ON CONFLICT (rule_id, device_id) DO UPDATE SET last_match_at = EXCLUDED.last_match_at, hits = EXCLUDED.hits,
				last_message = EXCLUDED.last_message,
				fired_at = CASE WHEN syslog_rule_hits.last_match_at < now() - make_interval(secs => (SELECT clear_s FROM syslog_rules WHERE id = $1))
					THEN EXCLUDED.fired_at ELSE syslog_rule_hits.fired_at END`, h.rule, h.dev, h.at, h.n, h.msg); err != nil {
			s.Log.Warn("syslog: simpan aturan terpicu gagal", "galat", err)
		}
	}
}
