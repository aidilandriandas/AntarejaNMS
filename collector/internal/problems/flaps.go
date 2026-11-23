package problems

import (
	"context"
	"fmt"
)

// PortFlap jumlah perubahan status satu port dalam jendela waktu.
type PortFlap struct {
	DeviceID int64
	IfIndex  int
	Name     string
	Trap     int  // perubahan dari trap linkDown/linkUp
	Poll     int  // perubahan yang terlihat antar putaran SNMP
	Open     bool // problem naik-turun sedang terbuka
}

// Changes: pakai hitungan trap bila ada (lebih lengkap), selain itu hitungan polling. Tidak dijumlah: satu kejadian
// yang tertangkap dua sumber tidak boleh terhitung dua kali.
func (f PortFlap) Changes() int {
	if f.Trap > 0 {
		return f.Trap
	}
	return f.Poll
}

// FlapConds: dibuka bila perubahan >= limit dalam jendela; problem yang sudah terbuka bertahan selama port masih
// berubah minimal 2 kali (histeresis), lalu tertutup sendiri.
func FlapConds(list []PortFlap, devs []Device, limit, minutes int) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	for _, f := range list {
		d, ok := byID[f.DeviceID]
		if !ok || d.Status != "up" {
			continue
		}
		n := f.Changes()
		if n < limit && !(f.Open && n >= 2) {
			continue
		}
		idx := f.IfIndex
		v := float64(n)
		c := Cond{DeviceID: d.ID, Kind: "port_flap", IfIndex: &idx, Severity: "average", Value: &v,
			Title:  fmt.Sprintf("%s: port %s naik-turun %d kali dalam %d menit", d.Name, f.Name, n, minutes),
			Detail: "periksa kabel, konektor, atau sinyal radio; problem tertutup sendiri setelah port stabil"}
		if d.InMaintenance != "" {
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		} else if anc := downAncestor(*d, byID); anc != nil {
			c.Suppressed, c.SupNote = "parent", "terdampak: induk "+anc.Name+" down"
		}
		out = append(out, c)
	}
	return out
}

// loadFlaps: port infrastruktur (dipantau, punya tetangga LLDP/CDP, atau sambungan manual) yang berubah status dalam jendela.
// Port akses biasa (laptop, printer) tidak dinilai supaya tidak berisik.
func (e *Engine) loadFlaps(ctx context.Context) ([]PortFlap, int, int, error) {
	limit, minutes := 4, 15
	_ = e.DB.QueryRow(ctx, `SELECT COALESCE((SELECT value::text::int FROM settings WHERE key = 'threshold.flap_changes'), 4),
		COALESCE((SELECT value::text::int FROM settings WHERE key = 'threshold.flap_minutes'), 15)`).Scan(&limit, &minutes)
	if limit < 2 {
		limit = 2
	}
	if minutes < 5 {
		minutes = 5
	}
	rows, err := e.DB.Query(ctx, `SELECT pc.device_id, pc.if_index, COALESCE(NULLIF(i.name, ''), 'if' || pc.if_index),
			count(*) FILTER (WHERE pc.source = 'trap'), count(*) FILTER (WHERE pc.source = 'poll'),
			EXISTS (SELECT 1 FROM problems p WHERE p.device_id = pc.device_id AND p.kind = 'port_flap' AND p.if_index = pc.if_index AND p.resolved_at IS NULL)
		FROM port_changes pc JOIN interfaces i ON i.device_id = pc.device_id AND i.if_index = pc.if_index
		WHERE pc.at > now() - make_interval(mins => $1)
		  AND (EXISTS (SELECT 1 FROM monitored_ports m WHERE m.device_id = pc.device_id AND m.if_index = pc.if_index)
		       OR EXISTS (SELECT 1 FROM neighbors n WHERE n.device_id = pc.device_id AND n.local_if_index = pc.if_index)
		       OR EXISTS (SELECT 1 FROM device_links l WHERE (l.a_device_id = pc.device_id AND l.a_if_index = pc.if_index)
		                                               OR (l.b_device_id = pc.device_id AND l.b_if_index = pc.if_index)))
		GROUP BY pc.device_id, pc.if_index, i.name`, minutes)
	if err != nil {
		return nil, limit, minutes, err
	}
	defer rows.Close()
	var out []PortFlap
	for rows.Next() {
		var f PortFlap
		if err := rows.Scan(&f.DeviceID, &f.IfIndex, &f.Name, &f.Trap, &f.Poll, &f.Open); err != nil {
			return nil, limit, minutes, err
		}
		out = append(out, f)
	}
	return out, limit, minutes, rows.Err()
}
