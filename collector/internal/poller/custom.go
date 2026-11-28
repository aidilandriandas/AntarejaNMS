package poller

import (
	"math"
	"sort"
	"strconv"
	"strings"

	"github.com/aidilandriandas/antarejanms/collector/internal/profiles"
	"github.com/aidilandriandas/antarejanms/collector/internal/store"
)

// maxCustomRows batas baris per OID tabel (supaya OID yang salah pilih tidak membanjiri sensor).
const maxCustomRows = 64

// CustomSensors membaca OID khusus dari templat perangkat (merek apa pun: Cambium, Mimosa, Siklu, dll.).
// Mode "get" = satu nilai; "walk" = satu kolom tabel, satu sensor per baris. Nilai dikali Scale lalu dinilai
// dengan ambang kelasnya (bawaan atau ambang khusus perangkat/templat).
func CustomSensors(q profiles.Q, oids []store.CustomOID, ov map[string][2]float64) []Sensor {
	var out []Sensor
	add := func(o store.CustomOID, key, label string, v any) {
		f, ok := customNum(v)
		if !ok {
			return
		}
		scale := o.Scale
		if scale == 0 {
			scale = 1
		}
		r := profiles.Reading{Key: key, Class: o.Class, Label: label, Unit: o.Unit, Value: math.Round(f*scale*1000) / 1000, Group: o.Group}
		if r.Group == "" {
			r.Group = "sistem"
		}
		profiles.JudgeWith(&r, ov)
		val := r.Value
		out = append(out, Sensor{Key: r.Key, Class: r.Class, Group: r.Group, Label: r.Label, Unit: r.Unit, Value: &val, Status: r.Status})
	}
	for _, o := range oids {
		oid := "." + strings.TrimPrefix(strings.TrimSpace(o.OID), ".")
		key := "ox:" + strconv.FormatInt(o.ID, 10)
		if o.Mode == "walk" {
			res, err := q.Walk(oid)
			if err != nil {
				continue
			}
			idx := make([]string, 0, len(res))
			for k := range res {
				idx = append(idx, k)
			}
			sort.Strings(idx)
			if len(idx) > maxCustomRows {
				idx = idx[:maxCustomRows]
			}
			for _, i := range idx {
				label := o.Label
				if len(idx) > 1 {
					label += " " + i
				}
				add(o, key+":"+i, label, res[i])
			}
			continue
		}
		res, err := q.Get([]string{oid})
		if err != nil {
			continue
		}
		if v, ok := res[oid]; ok {
			add(o, key, o.Label, v)
		}
	}
	return out
}

func customNum(v any) (float64, bool) {
	switch x := v.(type) {
	case float64:
		return x, !math.IsNaN(x) && !math.IsInf(x, 0)
	case string:
		f, err := strconv.ParseFloat(strings.TrimSpace(strings.Fields(x + " 0")[0]), 64)
		return f, err == nil
	}
	return 0, false
}
