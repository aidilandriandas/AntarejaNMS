package problems

import (
	"context"
	"fmt"
	"strconv"

	"github.com/aidilandriandas/antarejanms/collector/internal/anomaly"
)

// OpenAnomaly anomali terbuka (tabel anomalies) yang akan dijadikan problem.
type OpenAnomaly struct {
	ID       int64
	DeviceID int64
	Metric   string
	IfIndex  *int
	Message  string
	Value    float64
}

// AnomalyConds: satu problem per anomali terbuka, kunci sensor "anm:<metric>[:if<n>]".
// Anomali dari perangkat yang tidak ada di daftar (dinonaktifkan) dilewati.
func AnomalyConds(list []OpenAnomaly, devs []Device, severity string) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	for _, a := range list {
		d, ok := byID[a.DeviceID]
		if !ok {
			continue
		}
		sk := "anm:" + a.Metric
		if a.IfIndex != nil {
			sk += ":if" + strconv.Itoa(*a.IfIndex)
		}
		v := a.Value
		c := Cond{DeviceID: d.ID, Kind: "anomaly", Sensor: sk, IfIndex: a.IfIndex, Severity: severity, Value: &v,
			Title: trimKey2(fmt.Sprintf("%s: %s", d.Name, a.Message), 250), Detail: "anomali #" + strconv.FormatInt(a.ID, 10) + ", dibanding jam yang sama 14 hari terakhir"}
		if d.InMaintenance != "" {
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		} else if anc := downAncestor(*d, byID); anc != nil {
			c.Suppressed, c.SupNote = "parent", "terdampak: induk "+anc.Name+" down"
		}
		out = append(out, c)
	}
	return out
}

// loadAnomalies membaca setelan dan anomali terbuka. severity "off" berarti anomali tidak dijadikan problem.
func (e *Engine) loadAnomalies(ctx context.Context) (string, []OpenAnomaly, error) {
	sev := "off"
	_ = e.DB.QueryRow(ctx, `SELECT COALESCE((SELECT value #>> '{}' FROM settings WHERE key = 'anomaly.problem_severity'), 'off')`).Scan(&sev)
	if sev != "info" && sev != "warning" && sev != "average" && sev != "high" {
		return "off", nil, nil
	}
	rows, err := e.DB.Query(ctx, `SELECT a.id, a.device_id, a.metric, a.if_index, a.direction, a.value, a.baseline, COALESCE(a.if_name, '')
		FROM anomalies a WHERE a.ended_at IS NULL`)
	if err != nil {
		return sev, nil, err
	}
	defer rows.Close()
	var out []OpenAnomaly
	for rows.Next() {
		var a OpenAnomaly
		var dir, port string
		var base float64
		if err := rows.Scan(&a.ID, &a.DeviceID, &a.Metric, &a.IfIndex, &dir, &a.Value, &base, &port); err != nil {
			return sev, nil, err
		}
		a.Message = anomaly.Describe(a.Metric, port, dir, a.Value, base)
		out = append(out, a)
	}
	return sev, out, rows.Err()
}
