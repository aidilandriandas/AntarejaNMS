package problems

import (
	"context"
	"fmt"
	"strconv"
	"time"
)

// Check cek layanan yang sedang bermasalah (down, lambat, atau sertifikat hampir habis).
type Check struct {
	ID        int64
	DeviceID  int64
	Name      string
	Severity  string
	Status    string
	LastMS    *float64
	LastError string
	SlowMS    *int
	CertExp   *time.Time
	CertWarn  *int
}

// CheckConds: problem "check" per cek, kunci "chk:<id>" (down/lambat) dan "chk:<id>:cert" (sertifikat).
// Ikut maintenance dan korelasi induk perangkatnya. Cek milik perangkat nonaktif dilewati.
func CheckConds(list []Check, devs []Device, now time.Time) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	add := func(d *Device, c Cond) {
		if d.InMaintenance != "" {
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		} else if anc := downAncestor(*d, byID); anc != nil {
			c.Suppressed, c.SupNote = "parent", "terdampak: induk "+anc.Name+" down"
		} else if d.Status == "down" {
			c.Suppressed, c.SupNote = "parent", "terdampak: perangkatnya sendiri down"
		}
		out = append(out, c)
	}
	for _, k := range list {
		d, ok := byID[k.DeviceID]
		if !ok {
			continue
		}
		sk := "chk:" + strconv.FormatInt(k.ID, 10)
		switch k.Status {
		case "down":
			add(d, Cond{DeviceID: d.ID, Kind: "check", Sensor: sk, Severity: k.Severity, Value: k.LastMS,
				Title: trimKey2(fmt.Sprintf("%s: cek %s gagal", d.Name, k.Name), 250), Detail: trimKey2(k.LastError, 500)})
		case "slow":
			if k.LastMS != nil && k.SlowMS != nil {
				add(d, Cond{DeviceID: d.ID, Kind: "check", Sensor: sk, Severity: "warning", Value: k.LastMS,
					Title:  trimKey2(fmt.Sprintf("%s: cek %s lambat", d.Name, k.Name), 250),
					Detail: fmt.Sprintf("%.0f ms, ambang %d ms", *k.LastMS, *k.SlowMS)})
			}
		}
		if k.CertExp != nil && k.CertWarn != nil {
			left := k.CertExp.Sub(now)
			if left < time.Duration(*k.CertWarn)*24*time.Hour {
				days := int(left.Hours() / 24)
				detail := fmt.Sprintf("berakhir %s, sisa %d hari", k.CertExp.Local().Format("02/01/2006"), days)
				sev := "warning"
				if left <= 0 {
					detail, sev = "sudah berakhir "+k.CertExp.Local().Format("02/01/2006"), "average"
				}
				v := float64(days)
				add(d, Cond{DeviceID: d.ID, Kind: "check", Sensor: sk + ":cert", Severity: sev, Value: &v,
					Title: trimKey2(fmt.Sprintf("%s: sertifikat %s hampir habis", d.Name, k.Name), 250), Detail: detail})
			}
		}
	}
	return out
}

func (e *Engine) loadChecks(ctx context.Context) ([]Check, error) {
	rows, err := e.DB.Query(ctx, `SELECT id, device_id, name, severity, status, last_ms, COALESCE(last_error, ''), slow_ms, cert_expires_at, cert_warn_days
		FROM service_checks WHERE enabled AND (status IN ('down', 'slow') OR cert_expires_at IS NOT NULL)`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Check
	for rows.Next() {
		var k Check
		var ms *float32
		if err := rows.Scan(&k.ID, &k.DeviceID, &k.Name, &k.Severity, &k.Status, &ms, &k.LastError, &k.SlowMS, &k.CertExp, &k.CertWarn); err != nil {
			return nil, err
		}
		if ms != nil {
			v := float64(*ms)
			k.LastMS = &v
		}
		out = append(out, k)
	}
	return out, rows.Err()
}
