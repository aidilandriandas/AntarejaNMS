package problems

import (
	"context"
	"fmt"
)

// stpFlapLimit: perubahan topologi STP di sebanyak ini putaran SNMP dalam 1 jam = STP tidak stabil (loop, port
// naik-turun, atau root berpindah-pindah).
const stpFlapLimit = 3

type STPFlap struct {
	DeviceID int64
	Cycles   int
}

// STPConds: problem "stp" (Warning) per switch. Ikut maintenance; ditekan bila switch sendiri down.
func STPConds(list []STPFlap, devs []Device) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	for _, f := range list {
		d, ok := byID[f.DeviceID]
		if !ok || f.Cycles < stpFlapLimit {
			continue
		}
		v := float64(f.Cycles)
		c := Cond{DeviceID: d.ID, Kind: "stp", Severity: "warning", Value: &v,
			Title:  trimKey2(fmt.Sprintf("%s: topologi STP tidak stabil", d.Name), 250),
			Detail: fmt.Sprintf("Topologi berubah di %d pembacaan dalam 1 jam terakhir. Periksa port yang naik-turun atau kemungkinan loop.", f.Cycles)}
		switch {
		case d.InMaintenance != "":
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		case d.Status == "down":
			c.Suppressed, c.SupNote = "parent", "terdampak: switchnya sendiri down"
		}
		out = append(out, c)
	}
	return out
}

func (e *Engine) loadSTP(ctx context.Context) ([]STPFlap, error) {
	rows, err := e.DB.Query(ctx, `SELECT device_id, count(*) FROM events WHERE kind = 'stp' AND message LIKE 'Topologi STP berubah%'
		AND at > now() - interval '1 hour' GROUP BY device_id HAVING count(*) >= $1`, stpFlapLimit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []STPFlap
	for rows.Next() {
		var f STPFlap
		if err := rows.Scan(&f.DeviceID, &f.Cycles); err != nil {
			return nil, err
		}
		out = append(out, f)
	}
	return out, rows.Err()
}
