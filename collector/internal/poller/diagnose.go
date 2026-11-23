package poller

import (
	"context"
	"math"
	"regexp"
	"strings"
	"time"

	"github.com/aidilandriandas/antarejanms/collector/internal/profiles"
)

// Diagnosis isi berkas diagnosa SNMP satu perangkat. Hanya hasil baca (GET/WALK), tanpa kredensial.
// Dipakai untuk membuat atau memperbaiki profil perangkat yang belum dikenal (mis. NVR atau kamera).
type Diagnosis struct {
	Generated   time.Time         `json:"generated"`
	Device      string            `json:"device"`
	Host        string            `json:"host"`
	SNMPVersion string            `json:"snmp_version"`
	Error       string            `json:"error,omitempty"`
	SysName     string            `json:"sys_name,omitempty"`
	SysDescr    string            `json:"sys_descr,omitempty"`
	SysObjectID string            `json:"sys_object_id,omitempty"`
	UptimeS     int64             `json:"uptime_s,omitempty"`
	Profiles    []string          `json:"profiles_detected"`
	Readings    []DiagReading     `json:"readings"`
	Interfaces  []DiagIf          `json:"interfaces"`
	Neighbors   int               `json:"lldp_cdp_neighbors"`
	Standard    map[string]bool   `json:"standard_mibs"` // MIB standar yang menjawab
	Enterprise  string            `json:"enterprise_oid,omitempty"`
	Walk        [][2]string       `json:"enterprise_walk"`
	Truncated   bool              `json:"enterprise_walk_truncated"`
	Notes       map[string]string `json:"notes,omitempty"`
}

type DiagReading struct {
	Key    string   `json:"key"`
	Class  string   `json:"class"`
	Label  string   `json:"label"`
	Unit   string   `json:"unit,omitempty"`
	Value  *float64 `json:"value"`
	Text   string   `json:"text,omitempty"`
	Status string   `json:"status"`
}

type DiagIf struct {
	Index        int    `json:"index"`
	Name         string `json:"name"`
	Alias        string `json:"alias,omitempty"`
	SpeedBps     uint64 `json:"speed_bps"`
	OperUp       bool   `json:"oper_up"`
	HasHCCounter bool   `json:"hc_counters"`
}

// MIB standar yang diperiksa: satu OID per MIB, cukup untuk tahu MIB itu didukung.
var standardProbes = map[string]string{
	"HOST-RESOURCES (CPU)":     ".1.3.6.1.2.1.25.3.3.1.2",
	"HOST-RESOURCES (storage)": ".1.3.6.1.2.1.25.2.3.1.3",
	"ENTITY-SENSOR":            ".1.3.6.1.2.1.99.1.1.1.4",
	"UPS-MIB":                  ".1.3.6.1.2.1.33.1.2.1",
	"LLDP-MIB":                 ".1.0.8802.1.1.2.1.4.1.1.9",
	"CISCO-CDP":                ".1.3.6.1.4.1.9.9.23.1.2.1.1.6",
	"IF-MIB (ifXTable)":        ".1.3.6.1.2.1.31.1.1.1.1",
	"IP-MIB (ARP)":             ".1.3.6.1.2.1.4.22.1.2",
}

const diagWalkLimit = 400

var enterpriseRe = regexp.MustCompile(`^\.?1\.3\.6\.1\.4\.1\.(\d+)`)

// Diagnose membaca perangkat sekarang untuk berkas diagnosa.
func (p *Poller) Diagnose(ctx context.Context, id int64) (Diagnosis, error) {
	d, err := p.Store.Device(ctx, id)
	if err != nil {
		return Diagnosis{}, err
	}
	out := Diagnosis{Generated: time.Now(), Device: d.Name, Host: d.Host, SNMPVersion: d.SNMPVersion, Standard: map[string]bool{},
		Profiles: []string{}, Readings: []DiagReading{}, Interfaces: []DiagIf{}, Walk: [][2]string{}, Notes: map[string]string{}}
	if !d.SNMPEnabled {
		out.Error = "SNMP tidak diaktifkan untuk perangkat ini"
		return out, nil
	}
	cr, err := p.Credentials(d)
	if err != nil {
		out.Error = "kredensial SNMP tidak bisa dibuka"
		return out, nil
	}
	sys, err := p.SNMP.System(d.Host, cr)
	if err != nil {
		out.Error = "SNMP tidak menjawab: " + err.Error()
		return out, nil
	}
	out.SysName, out.SysDescr, out.SysObjectID, out.UptimeS = sys.Name, sys.Descr, sys.ObjectID, sys.UptimeSeconds()

	if ifs, err := p.SNMP.Interfaces(d.Host, cr); err == nil {
		for _, i := range ifs {
			out.Interfaces = append(out.Interfaces, DiagIf{Index: i.Index, Name: i.Name, Alias: i.Alias, SpeedBps: i.SpeedBps, OperUp: i.OperUp, HasHCCounter: i.HC})
		}
		if nbs, err := p.SNMP.Neighbors(d.Host, cr, ifs); err == nil {
			out.Neighbors = len(nbs)
		} else {
			out.Notes["neighbors"] = err.Error()
		}
	} else {
		out.Notes["interfaces"] = err.Error()
	}

	sess, err := p.SNMP.Session(d.Host, cr)
	if err != nil {
		out.Notes["session"] = err.Error()
		return out, nil
	}
	defer sess.Close()
	out.Profiles = profiles.Detect(sess, sys.ObjectID)
	for _, r := range profiles.Collect(sess, out.Profiles) {
		x := DiagReading{Key: r.Key, Class: r.Class, Label: r.Label, Unit: r.Unit, Text: r.Text, Status: r.Status}
		if !math.IsNaN(r.Value) {
			v := r.Value
			x.Value = &v
		}
		out.Readings = append(out.Readings, x)
	}
	for name, oid := range standardProbes {
		if ctx.Err() != nil {
			break
		}
		rows, _, err := sess.WalkRaw(oid, 1)
		out.Standard[name] = err == nil && len(rows) > 0
	}
	if m := enterpriseRe.FindStringSubmatch(sys.ObjectID); m != nil {
		out.Enterprise = ".1.3.6.1.4.1." + m[1]
		rows, trunc, err := sess.WalkRaw(out.Enterprise, diagWalkLimit)
		for i := range rows {
			if len(rows[i][1]) > 120 {
				rows[i][1] = rows[i][1][:120] + "…"
			}
			rows[i][1] = strings.ToValidUTF8(rows[i][1], "?")
		}
		out.Walk, out.Truncated = rows, trunc
		if err != nil {
			out.Notes["enterprise_walk"] = err.Error()
		}
	}
	if out.Walk == nil {
		out.Walk = [][2]string{}
	}
	return out, nil
}
