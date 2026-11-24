package problems

import (
	"context"
	"fmt"
	"strconv"
)

// LogHit aturan pola syslog yang sedang terpicu pada satu perangkat (masih cocok dalam clear_s terakhir).
type LogHit struct {
	RuleID, DeviceID int64
	Name, Severity   string
	Hits, MinCount   int
	WindowS          int
	LastMessage      string
}

// LogConds: problem "log" per aturan per perangkat, kunci "log:<id>". Ikut maintenance; tidak ditekan induk down
// karena log datang dari perangkat itu sendiri (berarti masih terjangkau).
func LogConds(list []LogHit, devs []Device) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	for _, h := range list {
		d, ok := byID[h.DeviceID]
		if !ok {
			continue
		}
		v := float64(h.Hits)
		c := Cond{DeviceID: d.ID, Kind: "log", Sensor: "log:" + strconv.FormatInt(h.RuleID, 10), Severity: h.Severity, Value: &v,
			Title:  trimKey2(fmt.Sprintf("%s: log %s", d.Name, h.Name), 250),
			Detail: trimKey2(fmt.Sprintf("%d kali dalam %s. Terakhir: %s", h.Hits, dur(h.WindowS), h.LastMessage), 500)}
		if h.MinCount <= 1 {
			c.Detail = trimKey2("Terakhir: "+h.LastMessage, 500)
		}
		if d.InMaintenance != "" {
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		}
		out = append(out, c)
	}
	return out
}

func dur(s int) string {
	switch {
	case s%3600 == 0:
		return strconv.Itoa(s/3600) + " jam"
	case s%60 == 0:
		return strconv.Itoa(s/60) + " menit"
	}
	return strconv.Itoa(s) + " detik"
}

func (e *Engine) loadLogHits(ctx context.Context) ([]LogHit, error) {
	rows, err := e.DB.Query(ctx, `SELECT h.rule_id, h.device_id, r.name, r.severity, h.hits, r.min_count, r.window_s, COALESCE(h.last_message, '')
		FROM syslog_rule_hits h JOIN syslog_rules r ON r.id = h.rule_id AND r.enabled
		WHERE h.last_match_at > now() - make_interval(secs => r.clear_s)`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []LogHit
	for rows.Next() {
		var h LogHit
		if err := rows.Scan(&h.RuleID, &h.DeviceID, &h.Name, &h.Severity, &h.Hits, &h.MinCount, &h.WindowS, &h.LastMessage); err != nil {
			return nil, err
		}
		out = append(out, h)
	}
	return out, rows.Err()
}
