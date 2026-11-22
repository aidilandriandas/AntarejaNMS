package poller

import (
	"context"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/metrics"
	"github.com/aidilandriandas/antarejanms/collector/internal/profiles"
	"github.com/aidilandriandas/antarejanms/collector/internal/snmp"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// collectSensors mendeteksi profil perangkat (disimpan, diulang tiap 24 jam) lalu membaca sensornya.
func (p *Poller) collectSensors(ctx context.Context, d store.Device, cr snmp.Credentials, objectID string, batch *metrics.Batch, now time.Time) {
	sess, err := p.SNMP.Session(d.Host, cr)
	if err != nil {
		return
	}
	defer sess.Close()

	ids, refresh, err := p.Store.Profiles(ctx, d.ID, objectID, d.SysObjectID)
	if err != nil {
		p.Log.Warn("baca profil gagal", "perangkat", d.ID, "galat", err)
		return
	}
	if refresh {
		ids = strings.Join(profiles.Detect(sess, objectID), ",")
		if err := p.Store.SetProfiles(ctx, d.ID, ids); err != nil {
			p.Log.Warn("simpan profil gagal", "perangkat", d.ID, "galat", err)
		}
		p.Log.Info("profil perangkat", "perangkat", d.ID, "profil", ids)
	}
	if ids == "" {
		return
	}
	readings := profiles.Collect(sess, strings.Split(ids, ","), d.Limits().Classes)
	rows := make([]store.SensorRow, 0, len(readings))
	dev := strconv.FormatInt(d.ID, 10)
	for _, r := range readings {
		row := store.SensorRow{Key: r.Key, Class: r.Class, Group: r.Group, Label: r.Label, Unit: r.Unit, Text: r.Text, Status: r.Status}
		if !math.IsNaN(r.Value) {
			v := r.Value
			row.Value = &v
			batch.Add("antareja_sensor", map[string]string{"device_id": dev, "key": r.Key, "class": r.Class}, v, now)
		}
		rows = append(rows, row)
	}
	if err := p.Store.SaveSensors(ctx, d.ID, rows); err != nil {
		p.Log.Error("simpan sensor gagal", "perangkat", d.ID, "galat", err)
	}
}
