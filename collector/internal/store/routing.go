package store

import (
	"context"
	"fmt"

	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
)

var bgpStates = map[int]string{1: "idle", 2: "connect", 3: "active", 4: "opensent", 5: "openconfirm", 6: "established"}
var ospfStates = map[int]string{1: "down", 2: "attempt", 3: "init", 4: "2-way", 5: "exstart", 6: "exchange", 7: "loading", 8: "full"}

func BGPState(s int) string  { return name(bgpStates, s) }
func OSPFState(s int) string { return name(ospfStates, s) }

func name(m map[int]string, s int) string {
	if v, ok := m[s]; ok {
		return v
	}
	return fmt.Sprintf("status %d", s)
}

// SaveRouting menyimpan tabel BGP/OSPF hasil walk dan mengembalikan pesan perubahan status (untuk kejadian).
func (s Store) SaveRouting(ctx context.Context, deviceID int64, r snmp.Routing) ([]string, error) {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	var events []string
	if r.BGPOK {
		old := map[string][2]any{}
		rows, err := tx.Query(ctx, `SELECT host(remote_addr), state, missing FROM bgp_peers WHERE device_id = $1`, deviceID)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var a string
			var st int
			var miss bool
			if rows.Scan(&a, &st, &miss) == nil {
				old[a] = [2]any{st, miss}
			}
		}
		rows.Close()
		seen := map[string]bool{}
		for _, p := range r.BGP {
			seen[p.Addr] = true
			if o, ok := old[p.Addr]; ok && (o[0].(int) != p.State || o[1].(bool)) {
				events = append(events, fmt.Sprintf("BGP %s (AS %d): %s ke %s", p.Addr, p.RemoteAS, BGPState(o[0].(int)), BGPState(p.State)))
			}
			if _, err := tx.Exec(ctx, `INSERT INTO bgp_peers (device_id, remote_addr, remote_as, local_as, state, admin_status, since_s)
				VALUES ($1, $2::inet, $3, NULLIF($4, 0), $5, NULLIF($6, 0), $7)
				ON CONFLICT (device_id, remote_addr) DO UPDATE SET remote_as = EXCLUDED.remote_as, local_as = EXCLUDED.local_as,
					admin_status = EXCLUDED.admin_status, since_s = EXCLUDED.since_s, missing = FALSE, updated_at = now(),
					state_changed_at = CASE WHEN bgp_peers.state <> EXCLUDED.state OR bgp_peers.missing THEN now() ELSE bgp_peers.state_changed_at END,
					state = EXCLUDED.state`, deviceID, p.Addr, p.RemoteAS, r.LocalAS, p.State, int64(p.Admin), p.SinceSecs); err != nil {
				return nil, err
			}
		}
		for a, o := range old {
			if !seen[a] && !o[1].(bool) {
				events = append(events, "BGP "+a+": sesi hilang dari tabel router")
				if _, err := tx.Exec(ctx, `UPDATE bgp_peers SET missing = TRUE, state_changed_at = now(), updated_at = now()
					WHERE device_id = $1 AND remote_addr = $2::inet`, deviceID, a); err != nil {
					return nil, err
				}
			}
		}
	}
	if r.OSPFOK {
		old := map[string][2]any{}
		rows, err := tx.Query(ctx, `SELECT host(addr), state, missing FROM ospf_neighbors WHERE device_id = $1`, deviceID)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var a string
			var st int
			var miss bool
			if rows.Scan(&a, &st, &miss) == nil {
				old[a] = [2]any{st, miss}
			}
		}
		rows.Close()
		seen := map[string]bool{}
		for _, n := range r.OSPF {
			seen[n.Addr] = true
			if o, ok := old[n.Addr]; ok && (o[0].(int) != n.State || o[1].(bool)) {
				events = append(events, fmt.Sprintf("OSPF %s (router %s): %s ke %s", n.Addr, n.RouterID, OSPFState(o[0].(int)), OSPFState(n.State)))
			}
			if _, err := tx.Exec(ctx, `INSERT INTO ospf_neighbors (device_id, addr, router_id, state) VALUES ($1, $2::inet, NULLIF($3, ''), $4)
				ON CONFLICT (device_id, addr) DO UPDATE SET router_id = EXCLUDED.router_id, missing = FALSE, updated_at = now(),
					state_changed_at = CASE WHEN ospf_neighbors.state <> EXCLUDED.state OR ospf_neighbors.missing THEN now() ELSE ospf_neighbors.state_changed_at END,
					state = EXCLUDED.state`, deviceID, n.Addr, n.RouterID, n.State); err != nil {
				return nil, err
			}
		}
		for a, o := range old {
			if !seen[a] && !o[1].(bool) {
				events = append(events, "OSPF "+a+": tetangga hilang")
				if _, err := tx.Exec(ctx, `UPDATE ospf_neighbors SET missing = TRUE, state_changed_at = now(), updated_at = now()
					WHERE device_id = $1 AND addr = $2::inet`, deviceID, a); err != nil {
					return nil, err
				}
			}
		}
	}
	return events, tx.Commit(ctx)
}
