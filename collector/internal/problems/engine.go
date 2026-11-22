package problems

import (
	"context"
	"encoding/json"
	"math"
	"strconv"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// StatsSource adalah pengamatan di memori collector (lihat poller.Stats).
type StatsSource interface {
	PingAvg(dev int64) (rtt, loss float64, n int)
	SNMPFails(dev int64) (n int, known bool)
	Util(dev int64, idx int) (float64, bool)
}

type Engine struct {
	DB    *pgxpool.Pool
	Stats StatsSource
}

type Outcome struct {
	Opened, Resolved, Open int
	Summary                string
}

// Run menilai semua perangkat lalu menyelaraskan tabel problems dalam satu transaksi.
func (e *Engine) Run(ctx context.Context) (Outcome, error) {
	var out Outcome
	th, err := e.thresholds(ctx)
	if err != nil {
		return out, err
	}
	devs, err := e.load(ctx, th)
	if err != nil {
		return out, err
	}
	res := Evaluate(devs, th)
	out.Summary = Summary(res)

	tx, err := e.DB.Begin(ctx)
	if err != nil {
		return out, err
	}
	defer tx.Rollback(ctx) //nolint:errcheck

	rows, err := tx.Query(ctx, `SELECT id, device_id, kind, if_index, sensor_key FROM problems WHERE resolved_at IS NULL FOR UPDATE`)
	if err != nil {
		return out, err
	}
	open := map[string]int64{}
	for rows.Next() {
		var id, dev int64
		var kind string
		var idx *int
		var sk *string
		if err := rows.Scan(&id, &dev, &kind, &idx, &sk); err != nil {
			rows.Close()
			return out, err
		}
		if sk != nil {
			open[SensorKey(dev, *sk)] = id
		} else {
			open[key(dev, kind, idx)] = id
		}
	}
	rows.Close()

	b := &pgx.Batch{}
	want := map[string]bool{}
	for _, c := range res.Conds {
		want[c.Key()] = true
		sup, note := nullable(c.Suppressed), nullable(c.SupNote)
		if id, ok := open[c.Key()]; ok {
			b.Queue(`UPDATE problems SET severity = $2, title = $3, detail = $4, last_value = $5, suppressed = $6, suppressed_note = $7 WHERE id = $1`,
				id, c.Severity, c.Title, c.Detail, c.Value, sup, note)
			continue
		}
		b.Queue(`INSERT INTO problems (device_id, kind, if_index, sensor_key, severity, title, detail, last_value, suppressed, suppressed_note)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) ON CONFLICT DO NOTHING`, c.DeviceID, c.Kind, c.IfIndex, nullable(c.Sensor), c.Severity, c.Title, c.Detail, c.Value, sup, note)
		out.Opened++
	}
	for k, id := range open {
		if !want[k] && !res.Unknown[k] {
			b.Queue(`UPDATE problems SET resolved_at = now() WHERE id = $1`, id)
			out.Resolved++
		}
	}
	if err := tx.SendBatch(ctx, b).Close(); err != nil {
		return out, err
	}
	if err := tx.Commit(ctx); err != nil {
		return out, err
	}
	out.Open = len(open) + out.Opened - out.Resolved
	return out, nil
}

func nullable(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func (e *Engine) thresholds(ctx context.Context) (Thresholds, error) {
	th := Thresholds{LatencyMS: 150, LossPct: 20, UtilPct: 90, SNMPFailCycles: 2, ConflictChanges: 3, ConflictHours: 6}
	rows, err := e.DB.Query(ctx, `SELECT key, value FROM settings WHERE key LIKE 'threshold.%' OR key LIKE 'ipam.%'`)
	if err != nil {
		return th, err
	}
	defer rows.Close()
	for rows.Next() {
		var k string
		var raw []byte
		if err := rows.Scan(&k, &raw); err != nil {
			return th, err
		}
		var v float64
		if json.Unmarshal(raw, &v) != nil {
			// nilai bisa tersimpan sebagai string JSON ("150")
			var s string
			if json.Unmarshal(raw, &s) != nil {
				continue
			}
			if v, err = strconv.ParseFloat(s, 64); err != nil {
				continue
			}
		}
		if v <= 0 {
			continue
		}
		switch k {
		case "threshold.latency_ms":
			th.LatencyMS = v
		case "threshold.loss_pct":
			th.LossPct = v
		case "threshold.util_pct":
			th.UtilPct = v
		case "threshold.snmp_fail_cycles":
			th.SNMPFailCycles = int(math.Max(1, v))
		case "ipam.conflict_changes":
			th.ConflictChanges = int(math.Max(2, v))
		case "ipam.conflict_hours":
			th.ConflictHours = int(math.Max(1, v))
		}
	}
	return th, rows.Err()
}

func (e *Engine) load(ctx context.Context, th Thresholds) ([]Device, error) {
	rows, err := e.DB.Query(ctx, `
		SELECT d.id, d.name, d.category, d.status, COALESCE(d.status_reason, ''), d.fail_streak, COALESCE(d.parent_id, 0), d.ping_enabled, d.snmp_enabled, d.thresholds,
			COALESCE((SELECT m.title FROM maintenances m
				WHERE now() >= m.starts_at AND now() < m.ends_at
				  AND (m.scope = 'all' OR (m.scope = 'site' AND m.site_id = d.site_id)
				       OR (m.scope = 'devices' AND EXISTS (SELECT 1 FROM maintenance_devices md WHERE md.maintenance_id = m.id AND md.device_id = d.id)))
				ORDER BY m.ends_at DESC LIMIT 1), '')
		FROM devices d WHERE d.enabled`)
	if err != nil {
		return nil, err
	}
	var devs []Device
	idx := map[int64]int{}
	for rows.Next() {
		var d Device
		var thr []byte
		if err := rows.Scan(&d.ID, &d.Name, &d.Category, &d.Status, &d.Reason, &d.FailStreak, &d.ParentID, &d.PingEnabled, &d.SNMPEnabled, &thr, &d.InMaintenance); err != nil {
			rows.Close()
			return nil, err
		}
		lim := store.ParseLimits(thr)
		d.LatencyMS, d.LossPct, d.UtilPct = lim.LatencyMS, lim.LossPct, lim.UtilPct
		rtt, loss, n := e.Stats.PingAvg(d.ID)
		d.PingKnown, d.RTTAvg, d.LossAvg = n >= 3, rtt, loss
		d.SNMPFails, d.SNMPKnown = e.Stats.SNMPFails(d.ID)
		idx[d.ID] = len(devs)
		devs = append(devs, d)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	prow, err := e.DB.Query(ctx, `SELECT i.device_id, i.if_index, COALESCE(NULLIF(i.name, ''), 'if' || i.if_index), COALESCE(i.admin_up, false), COALESCE(i.oper_up, false)
		FROM monitored_ports mp JOIN interfaces i ON i.device_id = mp.device_id AND i.if_index = mp.if_index`)
	if err != nil {
		return nil, err
	}
	defer prow.Close()
	for prow.Next() {
		var dev int64
		var p Port
		if err := prow.Scan(&dev, &p.Index, &p.Name, &p.AdminUp, &p.OperUp); err != nil {
			return nil, err
		}
		i, ok := idx[dev]
		if !ok {
			continue
		}
		p.Util, p.UtilKnown = e.Stats.Util(dev, p.Index)
		devs[i].Ports = append(devs[i].Ports, p)
	}
	if err := prow.Err(); err != nil {
		return nil, err
	}

	srows, err := e.DB.Query(ctx, `SELECT device_id, key, label, unit, COALESCE(text, ''), status, value, updated_at > now() - interval '20 minutes' FROM device_sensors`)
	if err != nil {
		return nil, err
	}
	defer srows.Close()
	for srows.Next() {
		var dev int64
		var s Sensor
		if err := srows.Scan(&dev, &s.Key, &s.Label, &s.Unit, &s.Text, &s.Status, &s.Value, &s.Fresh); err != nil {
			return nil, err
		}
		if i, ok := idx[dev]; ok {
			devs[i].Sensors = append(devs[i].Sensors, s)
		}
	}
	if err := srows.Err(); err != nil {
		return nil, err
	}

	crows, err := e.DB.Query(ctx, `
		WITH c AS (SELECT reporter_id, ip, old_mac, new_mac FROM arp_changes WHERE at > now() - make_interval(hours => $1))
		SELECT reporter_id, host(ip), count(*),
			ARRAY(SELECT DISTINCT m FROM (SELECT old_mac AS m FROM c c2 WHERE c2.reporter_id = c.reporter_id AND c2.ip = c.ip
			                              UNION SELECT new_mac FROM c c3 WHERE c3.reporter_id = c.reporter_id AND c3.ip = c.ip) u ORDER BY m)
		FROM c GROUP BY reporter_id, ip`, th.ConflictHours)
	if err != nil {
		return nil, err
	}
	defer crows.Close()
	for crows.Next() {
		var dev int64
		var c Conflict
		if err := crows.Scan(&dev, &c.IP, &c.Changes, &c.MACs); err != nil {
			return nil, err
		}
		if i, ok := idx[dev]; ok {
			devs[i].Conflicts = append(devs[i].Conflicts, c)
		}
	}
	if err := crows.Err(); err != nil {
		return nil, err
	}

	cams, err := e.DB.Query(ctx, `SELECT device_id, id, name, COALESCE(health, ''), COALESCE(health_detail, ''), COALESCE(health_at > now() - interval '40 minutes', false)
		FROM cameras WHERE enabled AND device_id IS NOT NULL`)
	if err != nil {
		return nil, err
	}
	defer cams.Close()
	for cams.Next() {
		var dev int64
		var c CamHealth
		if err := cams.Scan(&dev, &c.ID, &c.Name, &c.Health, &c.Detail, &c.Fresh); err != nil {
			return nil, err
		}
		if i, ok := idx[dev]; ok {
			devs[i].Cameras = append(devs[i].Cameras, c)
		}
	}
	return devs, cams.Err()
}
