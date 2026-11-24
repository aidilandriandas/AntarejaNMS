package problems

import (
	"context"
	"fmt"
)

// RoutingRow sesi BGP atau tetangga OSPF yang bermasalah.
type RoutingRow struct {
	DeviceID int64
	Proto    string // bgp | ospf
	Addr     string
	RemoteAS *int64
	RouterID string
	State    int
	Admin    *int
	Missing  bool
}

var bgpName = map[int]string{1: "idle", 2: "connect", 3: "active", 4: "opensent", 5: "openconfirm"}
var ospfName = map[int]string{1: "down", 2: "attempt", 3: "init", 5: "exstart", 6: "exchange", 7: "loading"}

// RoutingConds: problem "routing" kunci "bgp:<ip>" / "ospf:<ip>". BGP: sesi yang di-start admin tetapi tidak
// established (High), atau hilang dari tabel (Average). OSPF: tetangga tidak full/2-way atau hilang (High).
// Ditekan bila router sendiri down atau dalam maintenance.
func RoutingConds(rows []RoutingRow, devs []Device) []Cond {
	byID := make(map[int64]*Device, len(devs))
	for i := range devs {
		byID[devs[i].ID] = &devs[i]
	}
	var out []Cond
	for _, r := range rows {
		d, ok := byID[r.DeviceID]
		if !ok {
			continue
		}
		var c Cond
		switch r.Proto {
		case "bgp":
			if !r.Missing && (r.State == 6 || (r.Admin != nil && *r.Admin == 1)) {
				continue
			}
			as := ""
			if r.RemoteAS != nil {
				as = fmt.Sprintf(" (AS %d)", *r.RemoteAS)
			}
			c = Cond{DeviceID: d.ID, Kind: "routing", Sensor: "bgp:" + r.Addr, Severity: "high",
				Title: trimKey2(fmt.Sprintf("%s: sesi BGP ke %s%s %s", d.Name, r.Addr, as, bgpName[r.State]), 250)}
			if r.Missing {
				c.Severity, c.Title = "average", trimKey2(fmt.Sprintf("%s: sesi BGP ke %s%s hilang dari tabel", d.Name, r.Addr, as), 250)
			}
		case "ospf":
			if !r.Missing && (r.State == 8 || r.State == 4) {
				continue
			}
			rid := ""
			if r.RouterID != "" {
				rid = " (router " + r.RouterID + ")"
			}
			st := ospfName[r.State]
			if r.Missing {
				st = "hilang"
			}
			c = Cond{DeviceID: d.ID, Kind: "routing", Sensor: "ospf:" + r.Addr, Severity: "high",
				Title: trimKey2(fmt.Sprintf("%s: tetangga OSPF %s%s %s", d.Name, r.Addr, rid, st), 250)}
		default:
			continue
		}
		c.Detail = "Tekan Lupakan di halaman Routing perangkat bila tetangga ini memang sudah dibongkar."
		v := float64(r.State)
		c.Value = &v
		switch {
		case d.InMaintenance != "":
			c.Suppressed, c.SupNote = "maintenance", "maintenance: "+d.InMaintenance
		case d.Status == "down":
			c.Suppressed, c.SupNote = "parent", "terdampak: routernya sendiri down"
		}
		out = append(out, c)
	}
	return out
}

func (e *Engine) loadRouting(ctx context.Context) ([]RoutingRow, error) {
	rows, err := e.DB.Query(ctx, `SELECT device_id, 'bgp', host(remote_addr), remote_as, '', state, admin_status, missing FROM bgp_peers
			WHERE (missing OR state <> 6) AND updated_at > now() - interval '30 minutes'
		UNION ALL SELECT device_id, 'ospf', host(addr), NULL, COALESCE(router_id, ''), state, NULL, missing FROM ospf_neighbors
			WHERE (missing OR state NOT IN (4, 8)) AND updated_at > now() - interval '30 minutes'`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []RoutingRow
	for rows.Next() {
		var r RoutingRow
		var adm *int16
		if err := rows.Scan(&r.DeviceID, &r.Proto, &r.Addr, &r.RemoteAS, &r.RouterID, &r.State, &adm, &r.Missing); err != nil {
			return nil, err
		}
		if adm != nil {
			a := int(*adm)
			r.Admin = &a
		}
		out = append(out, r)
	}
	return out, rows.Err()
}
