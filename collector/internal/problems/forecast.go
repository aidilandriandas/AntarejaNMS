package problems

import (
	"context"
	"fmt"
	"time"
)

// Forecast prediksi sensor yang mendekati batas.
type Forecast struct {
	DeviceID   int64
	Key, Class string
	Label      string
	Current    float64
	PerDay     float64
	R2         *float64
	ETA        time.Time
}

// Batas waktu prediksi menjadi problem. Tren disk harus cukup lurus (R² >= 0,5) supaya lonjakan sesaat tidak memicu.
var forecastRules = map[string][]struct {
	within   time.Duration
	severity string
}{
	"disk": {{2 * 24 * time.Hour, "average"}, {7 * 24 * time.Hour, "warning"}},
	"fuel": {{6 * time.Hour, "high"}, {24 * time.Hour, "average"}},
}

// ForecastConds: problem "forecast" kunci "fc:<kunci sensor>". Ikut maintenance.
func ForecastConds(list []Forecast, devs []Device, now time.Time) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	for _, f := range list {
		d, ok := byID[f.DeviceID]
		if !ok {
			continue
		}
		if f.Class == "disk" && (f.R2 == nil || *f.R2 < 0.5) {
			continue
		}
		left := f.ETA.Sub(now)
		sev := ""
		for _, r := range forecastRules[f.Class] {
			if left <= r.within {
				sev = r.severity
				break
			}
		}
		if sev == "" {
			continue
		}
		what := "penuh"
		rate := fmt.Sprintf("naik %.1f%% per hari", f.PerDay)
		if f.Class == "fuel" {
			what = "habis"
			rate = fmt.Sprintf("turun %.1f per jam", -f.PerDay/24)
		}
		v := left.Hours()
		c := Cond{DeviceID: d.ID, Kind: "forecast", Sensor: "fc:" + f.Key, Severity: sev, Value: &v,
			Title:  trimKey2(fmt.Sprintf("%s: %s diperkirakan %s dalam %s", d.Name, f.Label, what, human(left)), 250),
			Detail: trimKey2(fmt.Sprintf("Sekarang %.1f, %s. Perkiraan %s %s.", f.Current, rate, what, f.ETA.Local().Format("02/01 15.04")), 500)}
		if d.InMaintenance != "" {
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		}
		out = append(out, c)
	}
	return out
}

func human(d time.Duration) string {
	switch {
	case d < time.Hour:
		return "kurang dari 1 jam"
	case d < 48*time.Hour:
		return fmt.Sprintf("%d jam", int(d.Hours()))
	}
	return fmt.Sprintf("%d hari", int(d.Hours()/24))
}

func (e *Engine) loadForecasts(ctx context.Context) ([]Forecast, error) {
	rows, err := e.DB.Query(ctx, `SELECT device_id, key, class, label, current, per_day, r2, eta FROM sensor_forecasts
		WHERE eta IS NOT NULL AND eta < now() + interval '8 days' AND computed_at > now() - interval '3 hours'`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Forecast
	for rows.Next() {
		var f Forecast
		if err := rows.Scan(&f.DeviceID, &f.Key, &f.Class, &f.Label, &f.Current, &f.PerDay, &f.R2, &f.ETA); err != nil {
			return nil, err
		}
		out = append(out, f)
	}
	return out, rows.Err()
}
