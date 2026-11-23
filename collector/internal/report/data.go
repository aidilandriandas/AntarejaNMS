package report

import (
	"context"
	"sort"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

// DeviceAvail ketersediaan satu perangkat dalam periode.
type DeviceAvail struct {
	ID        int64
	Name      string
	Host      string
	Site      string
	Monitored float64 // detik
	Down      float64 // detik (di luar maintenance)
	Incidents int
	Avail     float64 // %
}

// SiteAvail ringkasan per site.
type SiteAvail struct {
	Site      string
	Devices   int
	Down      float64
	Incidents int
	Avail     float64
}

// DownNow perangkat yang sedang down saat laporan dibuat.
// IncRow satu insiden di laporan (terlama dulu).
type IncRow struct {
	ID       int64
	Title    string
	Site     string
	Severity string
	Status   string
	Seconds  float64
	Cause    string
}

type DownNow struct {
	Name, Host, Site string
	Since            time.Time
}

// Data isi laporan.
type Data struct {
	Period     Period
	SiteName   string // kosong = semua site
	Generated  time.Time
	Target     float64
	Total      SiteAvail
	Sites      []SiteAvail
	Worst      []DeviceAvail  // ketersediaan terendah (yang punya downtime), maks. 10
	BelowCount int            // jumlah perangkat di bawah target
	Problems   map[string]int // problem baru per severity
	ProblemsN  int
	OpenNow    int // problem terbuka saat ini
	OpenHigh   int // High/Disaster terbuka belum di-ACK
	DownNow    []DownNow
	Reboots    int
	ConfigChg  int
	SyslogErr  int
	BaseURL    string
	// Insiden yang dibuka dalam periode
	IncN      int
	IncActive int     // masih terbuka / ditangani sekarang
	IncNoRCA  int     // sudah pulih tetapi penyebab & tindakan belum diisi
	IncMTTA   float64 // detik rata-rata sampai ditangani (0 = tidak ada data)
	IncMTTR   float64 // detik rata-rata sampai pulih
	IncTop    []IncRow
}

const availSQL = `
WITH p AS (SELECT $1::timestamptz AS s, LEAST($2::timestamptz, now()) AS e),
outage AS (
    SELECT pr.id, pr.device_id, GREATEST(pr.started_at, p.s) AS os, LEAST(COALESCE(pr.resolved_at, now()), p.e) AS oe
    FROM problems pr CROSS JOIN p
    WHERE pr.kind = 'down' AND pr.started_at < p.e AND COALESCE(pr.resolved_at, now()) > p.s
),
net AS (
    SELECT o.*, GREATEST(0, EXTRACT(EPOCH FROM (o.oe - o.os)) - COALESCE((
        SELECT SUM(EXTRACT(EPOCH FROM (LEAST(o.oe, m.ends_at) - GREATEST(o.os, m.starts_at))))
        FROM maintenances m JOIN devices dd ON dd.id = o.device_id
        WHERE m.starts_at < o.oe AND m.ends_at > o.os
          AND (m.scope = 'all' OR (m.scope = 'site' AND m.site_id = dd.site_id)
               OR (m.scope = 'devices' AND EXISTS (SELECT 1 FROM maintenance_devices md WHERE md.maintenance_id = m.id AND md.device_id = o.device_id)))
    ), 0)) AS down_s
    FROM outage o
)
SELECT d.id, d.name, d.host, COALESCE(s.code, 'Tanpa site'),
    EXTRACT(EPOCH FROM (p.e - GREATEST(p.s, d.created_at)))::float8,
    COALESCE(SUM(n.down_s), 0)::float8,
    COUNT(n.id) FILTER (WHERE n.down_s > 0)
FROM devices d CROSS JOIN p
LEFT JOIN sites s ON s.id = d.site_id
LEFT JOIN net n ON n.device_id = d.id
WHERE d.created_at < p.e AND d.enabled AND ($3::bigint = 0 OR d.site_id = $3)
GROUP BY d.id, d.name, d.host, s.code, p.s, p.e`

// Build menghitung isi laporan. Rumus ketersediaan sama dengan halaman SLA bulanan:
// downtime = problem "down" yang beririsan dengan periode, dikurangi bagian di dalam jendela maintenance.
func Build(ctx context.Context, db *pgxpool.Pool, p Period, siteID int64) (Data, error) {
	d := Data{Period: p, Generated: time.Now(), Target: 99.5, Problems: map[string]int{}}
	_ = db.QueryRow(ctx, `SELECT value::text::float8 FROM settings WHERE key = 'sla.target_pct'`).Scan(&d.Target)
	if siteID > 0 {
		_ = db.QueryRow(ctx, `SELECT code || ' (' || name || ')' FROM sites WHERE id = $1`, siteID).Scan(&d.SiteName)
	}

	rows, err := db.Query(ctx, availSQL, p.Start, p.End, siteID)
	if err != nil {
		return d, err
	}
	var devs []DeviceAvail
	for rows.Next() {
		var x DeviceAvail
		if err := rows.Scan(&x.ID, &x.Name, &x.Host, &x.Site, &x.Monitored, &x.Down, &x.Incidents); err != nil {
			rows.Close()
			return d, err
		}
		if x.Monitored <= 0 {
			continue
		}
		if x.Down > x.Monitored {
			x.Down = x.Monitored
		}
		x.Avail = (1 - x.Down/x.Monitored) * 100
		devs = append(devs, x)
	}
	rows.Close()

	bySite := map[string]*struct {
		mon float64
		SiteAvail
	}{}
	var mon float64
	for _, x := range devs {
		mon += x.Monitored
		d.Total.Devices++
		d.Total.Down += x.Down
		d.Total.Incidents += x.Incidents
		s := bySite[x.Site]
		if s == nil {
			s = &struct {
				mon float64
				SiteAvail
			}{SiteAvail: SiteAvail{Site: x.Site}}
			bySite[x.Site] = s
		}
		s.mon += x.Monitored
		s.Devices++
		s.Down += x.Down
		s.Incidents += x.Incidents
		if x.Avail < d.Target {
			d.BelowCount++
		}
	}
	if mon > 0 {
		d.Total.Avail = (1 - d.Total.Down/mon) * 100
	} else {
		d.Total.Avail = -1
	}
	for _, s := range bySite {
		if s.mon > 0 {
			s.Avail = (1 - s.Down/s.mon) * 100
		}
		d.Sites = append(d.Sites, s.SiteAvail)
	}
	sort.Slice(d.Sites, func(i, j int) bool {
		if d.Sites[i].Avail != d.Sites[j].Avail {
			return d.Sites[i].Avail < d.Sites[j].Avail
		}
		return d.Sites[i].Site < d.Sites[j].Site
	})
	sort.Slice(devs, func(i, j int) bool {
		if devs[i].Avail != devs[j].Avail {
			return devs[i].Avail < devs[j].Avail
		}
		return devs[i].Name < devs[j].Name
	})
	for _, x := range devs {
		if x.Down <= 0 || len(d.Worst) >= 10 {
			break
		}
		d.Worst = append(d.Worst, x)
	}

	site := `($3::bigint = 0 OR dv.site_id = $3)`
	if rows, err := db.Query(ctx, `SELECT pr.severity, count(*) FROM problems pr JOIN devices dv ON dv.id = pr.device_id
		WHERE pr.started_at >= $1 AND pr.started_at < $2 AND `+site+` GROUP BY pr.severity`, p.Start, p.End, siteID); err == nil {
		for rows.Next() {
			var sev string
			var n int
			if rows.Scan(&sev, &n) == nil {
				d.Problems[sev] = n
				d.ProblemsN += n
			}
		}
		rows.Close()
	}
	_ = db.QueryRow(ctx, `SELECT count(*), count(*) FILTER (WHERE pr.severity IN ('high', 'disaster') AND pr.acked_at IS NULL)
		FROM problems pr JOIN devices dv ON dv.id = pr.device_id WHERE pr.resolved_at IS NULL AND ($1::bigint = 0 OR dv.site_id = $1)`, siteID).Scan(&d.OpenNow, &d.OpenHigh)
	if rows, err := db.Query(ctx, `SELECT dv.name, dv.host, COALESCE(s.code, ''), COALESCE(pr.started_at, dv.updated_at)
		FROM devices dv LEFT JOIN sites s ON s.id = dv.site_id
		LEFT JOIN problems pr ON pr.device_id = dv.id AND pr.kind = 'down' AND pr.resolved_at IS NULL
		WHERE dv.enabled AND dv.status = 'down' AND ($1::bigint = 0 OR dv.site_id = $1) ORDER BY 4 LIMIT 15`, siteID); err == nil {
		for rows.Next() {
			var x DownNow
			if rows.Scan(&x.Name, &x.Host, &x.Site, &x.Since) == nil {
				d.DownNow = append(d.DownNow, x)
			}
		}
		rows.Close()
	}
	_ = db.QueryRow(ctx, `SELECT count(*) FILTER (WHERE e.kind = 'reboot'), count(*) FILTER (WHERE e.kind = 'config_change')
		FROM events e JOIN devices dv ON dv.id = e.device_id WHERE e.at >= $1 AND e.at < $2 AND `+site, p.Start, p.End, siteID).Scan(&d.Reboots, &d.ConfigChg)
	_ = db.QueryRow(ctx, `SELECT count(*) FROM syslog sl LEFT JOIN devices dv ON dv.id = sl.device_id
		WHERE sl.received_at >= $1 AND sl.received_at < $2 AND sl.severity <= 3 AND ($3::bigint = 0 OR dv.site_id = $3)`, p.Start, p.End, siteID).Scan(&d.SyslogErr)
	buildIncidents(ctx, db, p, siteID, &d)
	return d, nil
}

// buildIncidents: insiden yang dibuka dalam periode (filter site lewat incidents.site_id).
func buildIncidents(ctx context.Context, db *pgxpool.Pool, p Period, siteID int64, d *Data) {
	_ = db.QueryRow(ctx, `SELECT count(*),
			count(*) FILTER (WHERE status IN ('open', 'handling')),
			count(*) FILTER (WHERE status IN ('recovered', 'closed') AND (COALESCE(cause, '') = '' OR COALESCE(action_taken, '') = '')),
			COALESCE(avg(EXTRACT(EPOCH FROM (handled_at - opened_at))) FILTER (WHERE handled_at IS NOT NULL), 0),
			COALESCE(avg(EXTRACT(EPOCH FROM (recovered_at - opened_at))) FILTER (WHERE recovered_at IS NOT NULL), 0)
		FROM incidents WHERE opened_at >= $1 AND opened_at < $2 AND ($3::bigint = 0 OR site_id = $3)`, p.Start, p.End, siteID).
		Scan(&d.IncN, &d.IncActive, &d.IncNoRCA, &d.IncMTTA, &d.IncMTTR)
	rows, err := db.Query(ctx, `SELECT i.id, i.title, COALESCE(s.code, ''), i.severity, i.status,
			EXTRACT(EPOCH FROM (COALESCE(i.recovered_at, i.closed_at, now()) - i.opened_at)), COALESCE(i.cause, '')
		FROM incidents i LEFT JOIN sites s ON s.id = i.site_id
		WHERE i.opened_at >= $1 AND i.opened_at < $2 AND ($3::bigint = 0 OR i.site_id = $3)
		ORDER BY 6 DESC LIMIT 5`, p.Start, p.End, siteID)
	if err != nil {
		return
	}
	defer rows.Close()
	for rows.Next() {
		var x IncRow
		if rows.Scan(&x.ID, &x.Title, &x.Site, &x.Severity, &x.Status, &x.Seconds, &x.Cause) == nil {
			d.IncTop = append(d.IncTop, x)
		}
	}
}
