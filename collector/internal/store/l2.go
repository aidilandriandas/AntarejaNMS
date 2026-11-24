package store

import (
	"context"
	"fmt"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
)

// SaveL2 menyimpan VLAN, PVID, dan STP. Kembalian: pesan kejadian (perubahan topologi STP, root bridge berganti).
func (s Store) SaveL2(ctx context.Context, deviceID int64, l snmp.L2, at time.Time) ([]string, error) {
	tx, err := s.DB.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx) //nolint:errcheck
	var events []string
	if l.VLANsOK {
		ids := make([]int32, 0, len(l.VLANs))
		for _, v := range l.VLANs {
			ids = append(ids, int32(v.ID))
			if _, err := tx.Exec(ctx, `INSERT INTO device_vlans (device_id, vlan_id, name) VALUES ($1, $2, NULLIF($3, ''))
				ON CONFLICT (device_id, vlan_id) DO UPDATE SET name = EXCLUDED.name, updated_at = now()`, deviceID, v.ID, cut64(v.Name)); err != nil {
				return nil, err
			}
		}
		if _, err := tx.Exec(ctx, `DELETE FROM device_vlans WHERE device_id = $1 AND NOT (vlan_id = ANY($2))`, deviceID, ids); err != nil {
			return nil, err
		}
	}
	if len(l.PVID) > 0 || len(l.PortSTP) > 0 {
		if _, err := tx.Exec(ctx, `UPDATE interfaces SET pvid = NULL, stp_state = NULL WHERE device_id = $1`, deviceID); err != nil {
			return nil, err
		}
		for ifi, v := range l.PVID {
			if _, err := tx.Exec(ctx, `UPDATE interfaces SET pvid = $3 WHERE device_id = $1 AND if_index = $2`, deviceID, ifi, v); err != nil {
				return nil, err
			}
		}
		for ifi, v := range l.PortSTP {
			if _, err := tx.Exec(ctx, `UPDATE interfaces SET stp_state = $3 WHERE device_id = $1 AND if_index = $2`, deviceID, ifi, v); err != nil {
				return nil, err
			}
		}
	}
	if st := l.STP; st != nil {
		var oldRoot *string
		var oldChanges *int64
		if err := tx.QueryRow(ctx, `SELECT stp_root, stp_top_changes FROM devices WHERE id = $1`, deviceID).Scan(&oldRoot, &oldChanges); err != nil {
			return nil, err
		}
		if oldChanges != nil && st.TopChanges > *oldChanges {
			events = append(events, fmt.Sprintf("Topologi STP berubah %d kali sejak pembacaan sebelumnya", st.TopChanges-*oldChanges))
		}
		if oldRoot != nil && st.Root != "" && *oldRoot != st.Root {
			events = append(events, fmt.Sprintf("Root bridge STP berganti dari %s ke %s", *oldRoot, st.Root))
		}
		last := at.Add(-time.Duration(st.SinceChangeS) * time.Second)
		if _, err := tx.Exec(ctx, `UPDATE devices SET stp_root = NULLIF($2, ''), stp_root_port = $3, stp_top_changes = $4, stp_last_change_at = $5 WHERE id = $1`,
			deviceID, st.Root, st.RootPort, st.TopChanges, last); err != nil {
			return nil, err
		}
	}
	return events, tx.Commit(ctx)
}

func cut64(s string) string {
	if len(s) > 64 {
		return s[:64]
	}
	return s
}
