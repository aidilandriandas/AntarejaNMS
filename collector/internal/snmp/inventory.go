package snmp

import (
	"errors"
	"math"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/gosnmp/gosnmp"
)

// HOST-RESOURCES-MIB (RFC 2790) hrSystemDate, SYNTAX DateAndTime (SNMPv2-TC, RFC 2579).
const oidHrSystemDate = ".1.3.6.1.2.1.25.1.2.0"

// ENTITY-MIB (RFC 6933) entPhysicalEntry, kolom: 5 Class, 9 FirmwareRev, 10 SoftwareRev, 11 SerialNum, 13 ModelName.
const (
	oidEntPhysical      = ".1.3.6.1.2.1.47.1.1.1.1"
	oidEntPhysicalClass = oidEntPhysical + ".5"
	entClassChassis     = 3
	entClassStack       = 11
)

// MIKROTIK-MIB: mtxrLicVersion (mtxrLicense 4, versi RouterOS), mtxrSerialNumber (mtxrSystem 3),
// mtxrBoardName (mtxrSystem 9). IEEE802dot11-MIB dot11manufacturerProductVersion (dot11ResourceInfoEntry 4,
// indeks ifIndex), dipakai Ubiquiti airOS dan radio lain yang tidak mengisi ENTITY-MIB.
const (
	oidMtxrLicVersion   = ".1.3.6.1.4.1.14988.1.1.4.4.0"
	oidMtxrSerialNumber = ".1.3.6.1.4.1.14988.1.1.7.3.0"
	oidMtxrBoardName    = ".1.3.6.1.4.1.14988.1.1.7.9.0"
	oidDot11ProductVer  = ".1.2.840.10036.3.1.2.1.4"
	mikrotikPrefix      = ".1.3.6.1.4.1.14988"
)

// Clock jam perangkat dibanding jam collector.
type Clock struct {
	SkewSec float64 `json:"skew_sec"` // jam perangkat dikurangi jam collector (positif = perangkat lebih cepat)
	HasTZ   bool    `json:"has_tz"`   // perangkat menyertakan zona waktu; bila tidak, selisih jam penuh diabaikan
}

// ParseDateAndTime membaca DateAndTime 8 oktet (tanpa zona) atau 11 oktet (dengan zona dari UTC).
// Tanpa zona waktu dibaca sebagai UTC; pemanggil menormalkan selisih zona.
func ParseDateAndTime(b []byte) (time.Time, bool, error) {
	if len(b) != 8 && len(b) != 11 {
		return time.Time{}, false, errors.New("DateAndTime harus 8 atau 11 oktet")
	}
	year := int(b[0])<<8 | int(b[1])
	month, day, hour, minute, sec, deci := int(b[2]), int(b[3]), int(b[4]), int(b[5]), int(b[6]), int(b[7])
	if year < 1990 || month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || sec > 60 || deci > 9 {
		return time.Time{}, false, errors.New("DateAndTime di luar rentang")
	}
	loc := time.UTC
	tz := false
	if len(b) == 11 {
		if (b[8] != '+' && b[8] != '-') || b[9] > 13 || b[10] > 59 {
			return time.Time{}, false, errors.New("zona DateAndTime tidak sah")
		}
		off := int(b[9])*3600 + int(b[10])*60
		if b[8] == '-' {
			off = -off
		}
		loc, tz = time.FixedZone("", off), true
	}
	return time.Date(year, time.Month(month), day, hour, minute, sec, deci*int(time.Second/10), loc), tz, nil
}

// Skew menghitung selisih. Tanpa zona waktu, selisih dibulatkan ke kelipatan 15 menit terdekat lalu dibuang,
// karena perangkat bisa memakai jam lokal (WIB, WITA, WIT) yang tidak diketahui collector.
func Skew(device time.Time, hasTZ bool, ref time.Time) float64 {
	d := device.Sub(ref).Seconds()
	if !hasTZ {
		const q = 15 * 60
		d -= q * math.Round(d/q)
	}
	return d
}

// Clock membaca hrSystemDate. Jam acuan = titik tengah permintaan supaya waktu tempuh jaringan tidak terhitung.
func (c Client) Clock(host string, cr Credentials) (Clock, error) {
	var out Clock
	g, err := c.connect(host, cr)
	if err != nil {
		return out, err
	}
	defer g.Conn.Close()
	t0 := time.Now()
	pkt, err := g.Get([]string{oidHrSystemDate})
	t1 := time.Now()
	if err != nil {
		return out, err
	}
	for _, v := range pkt.Variables {
		if missing(v) || v.Type != gosnmp.OctetString {
			continue
		}
		b, _ := v.Value.([]byte)
		dt, tz, err := ParseDateAndTime(b)
		if err != nil {
			return out, err
		}
		out.SkewSec, out.HasTZ = Skew(dt, tz, t0.Add(t1.Sub(t0)/2)), tz
		return out, nil
	}
	return out, errors.New("hrSystemDate tidak tersedia")
}

// Inventory versi perangkat lunak dan identitas perangkat keras (hanya baca).
type Inventory struct {
	Version string `json:"version,omitempty"`
	Model   string `json:"model,omitempty"`
	Serial  string `json:"serial,omitempty"`
}

// Inventory: MikroTik dari MIKROTIK-MIB; selain itu entri chassis ENTITY-MIB, lalu IEEE802dot11-MIB, lalu
// "Version x" dari sysDescr.
func (c Client) Inventory(host string, cr Credentials, sysDescr, sysObjectID string) (Inventory, error) {
	var inv Inventory
	g, err := c.connect(host, cr)
	if err != nil {
		return inv, err
	}
	defer g.Conn.Close()
	walk := walker(g)
	if sysObjectID == mikrotikPrefix || strings.HasPrefix(sysObjectID, mikrotikPrefix+".") {
		if pkt, err := g.Get([]string{oidMtxrLicVersion, oidMtxrSerialNumber, oidMtxrBoardName}); err == nil {
			for _, v := range pkt.Variables {
				if missing(v) {
					continue
				}
				switch s := clean(text(v.Value)); v.Name {
				case oidMtxrLicVersion:
					inv.Version = s
				case oidMtxrSerialNumber:
					inv.Serial = s
				case oidMtxrBoardName:
					inv.Model = s
				}
			}
		}
		if inv.Version != "" {
			return inv, nil
		}
	}
	var chassis, any []int
	_ = walk(oidEntPhysicalClass, func(idx string, v gosnmp.SnmpPDU) {
		i, err := strconv.Atoi(idx)
		if err != nil || len(any) > 5000 {
			return
		}
		any = append(any, i)
		if cl := gosnmp.ToBigInt(v.Value).Int64(); cl == entClassChassis || cl == entClassStack {
			chassis = append(chassis, i)
		}
	})
	sort.Ints(chassis)
	sort.Ints(any)
	pick := 0
	switch {
	case len(chassis) > 0:
		pick = chassis[0]
	case len(any) > 0:
		pick = any[0]
	}
	if pick > 0 {
		col := func(n int) string { return oidEntPhysical + "." + strconv.Itoa(n) + "." + strconv.Itoa(pick) }
		if pkt, err := g.Get([]string{col(9), col(10), col(11), col(13)}); err == nil {
			var fw, sw string
			for _, v := range pkt.Variables {
				if missing(v) {
					continue
				}
				s := clean(text(v.Value))
				switch v.Name {
				case col(9):
					fw = s
				case col(10):
					sw = s
				case col(11):
					inv.Serial = s
				case col(13):
					inv.Model = s
				}
			}
			inv.Version = sw
			if inv.Version == "" {
				inv.Version = fw
			}
		}
	}
	if inv.Version == "" {
		_ = walk(oidDot11ProductVer, func(_ string, v gosnmp.SnmpPDU) {
			if inv.Version == "" {
				inv.Version = clean(text(v.Value))
			}
		})
	}
	if inv.Version == "" {
		inv.Version = VersionFromDescr(sysDescr)
	}
	return inv, nil
}

var reVersion = regexp.MustCompile(`(?i)\bversion:?\s+v?([0-9][0-9A-Za-z.()_\-]*[0-9A-Za-z)])`)

// VersionFromDescr mengambil "Version x.y" dari sysDescr (mis. Cisco IOS "Version 15.2(4)E7,").
func VersionFromDescr(d string) string {
	if m := reVersion.FindStringSubmatch(d); m != nil {
		return clean(m[1])
	}
	return ""
}

func clean(s string) string {
	s = strings.TrimSpace(s)
	if len(s) > 120 {
		s = s[:120]
	}
	return s
}
