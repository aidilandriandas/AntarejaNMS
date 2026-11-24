package promx

import (
	"math"
	"sort"
	"strings"
	"unicode"
)

// Nama metrik yang dipakai, diverifikasi dari kode node_exporter (collector/cpu_common.go, meminfo.go,
// filesystem_common.go, systemd_linux.go) dan dokumen windows_exporter (docs/collector.cpu.md, memory.md,
// logical_disk.md, service.md).
const (
	nodeCPU       = "node_cpu_seconds_total"         // counter, label cpu, mode
	nodeMemTotal  = "node_memory_MemTotal_bytes"     // gauge
	nodeMemAvail  = "node_memory_MemAvailable_bytes" // gauge
	nodeFSSize    = "node_filesystem_size_bytes"     // gauge, label device, mountpoint, fstype, device_error
	nodeFSAvail   = "node_filesystem_avail_bytes"    // ruang yang bisa dipakai pengguna biasa
	nodeSystemd   = "node_systemd_unit_state"        // gauge, label name, state, type (collector systemd harus diaktifkan)
	winCPU        = "windows_cpu_time_seconds_total" // counter, label core, mode
	winMemTotal   = "windows_memory_physical_total_bytes"
	winMemFree    = "windows_memory_physical_free_bytes"
	winDiskSize   = "windows_logical_disk_size_bytes" // gauge, label volume
	winDiskFree   = "windows_logical_disk_free_bytes"
	winSvcState   = "windows_service_state" // gauge, label service, state; 1 = keadaan sekarang
	maxDisks      = 32
	maxServices   = 50
	statusMissing = "tidak dilaporkan exporter"
)

var wanted = map[string]bool{nodeCPU: true, nodeMemTotal: true, nodeMemAvail: true, nodeFSSize: true, nodeFSAvail: true, nodeSystemd: true,
	winCPU: true, winMemTotal: true, winMemFree: true, winDiskSize: true, winDiskFree: true, winSvcState: true}

// Keep dipakai Parse: hanya metrik di atas yang disimpan.
func Keep(name string) bool { return wanted[name] }

// Reading satu nilai turunan. Status numerik dinilai penerap dengan ambang kelas (cpu, memory, disk) per perangkat;
// layanan sudah berstatus.
type Reading struct {
	Key    string   `json:"key"`
	Class  string   `json:"class"`
	Label  string   `json:"label"`
	Unit   string   `json:"unit,omitempty"`
	Value  *float64 `json:"value,omitempty"`
	Text   string   `json:"text,omitempty"`
	Status string   `json:"status,omitempty"`
}

// CPUState counter CPU putaran sebelumnya (persen CPU butuh dua pembacaan).
type CPUState struct{ Idle, Total float64 }

// Compute menurunkan sensor dari hasil scrape. prev nil = pembacaan pertama (CPU belum bisa dihitung).
// Kembalian next untuk disimpan pemanggil sampai putaran berikutnya.
func Compute(samples []Sample, services []string, prev *CPUState) (out []Reading, next *CPUState, kind string) {
	by := map[string][]Sample{}
	for _, s := range samples {
		by[s.Name] = append(by[s.Name], s)
	}
	pct := func(v float64) *float64 { v = math.Round(v*10) / 10; return &v }

	switch {
	case len(by[nodeCPU]) > 0 || len(by[nodeMemTotal]) > 0 || len(by[nodeFSSize]) > 0:
		kind = "node"
	case len(by[winCPU]) > 0 || len(by[winMemTotal]) > 0 || len(by[winDiskSize]) > 0:
		kind = "windows"
	default:
		return nil, nil, ""
	}

	// CPU: 1 - (selisih idle / selisih total). iowait dihitung menganggur seperti alat Linux umumnya.
	cpuName, idleModes := nodeCPU, map[string]bool{"idle": true, "iowait": true}
	if kind == "windows" {
		cpuName, idleModes = winCPU, map[string]bool{"idle": true}
	}
	if list := by[cpuName]; len(list) > 0 {
		cur := &CPUState{}
		for _, s := range list {
			if math.IsNaN(s.Value) || math.IsInf(s.Value, 0) {
				continue
			}
			cur.Total += s.Value
			if idleModes[s.Labels["mode"]] {
				cur.Idle += s.Value
			}
		}
		next = cur
		if prev != nil {
			dt, di := cur.Total-prev.Total, cur.Idle-prev.Idle
			if dt > 0 && di >= 0 && di <= dt {
				out = append(out, Reading{Key: "px:cpu", Class: "cpu", Label: "CPU", Unit: "%", Value: pct((1 - di/dt) * 100)})
			}
		}
	}

	// Memori
	total, avail := one(by[nodeMemTotal]), one(by[nodeMemAvail])
	if kind == "windows" {
		total, avail = one(by[winMemTotal]), one(by[winMemFree])
	}
	if total > 0 && avail >= 0 && avail <= total {
		out = append(out, Reading{Key: "px:mem", Class: "memory", Label: "Memori", Unit: "%", Value: pct((1 - avail/total) * 100)})
	}

	// Disk
	type disk struct {
		name        string
		size, avail float64
	}
	var disks []disk
	if kind == "node" {
		av := map[string]float64{}
		for _, s := range by[nodeFSAvail] {
			av[s.Labels["mountpoint"]] = s.Value
		}
		seen := map[string]bool{}
		for _, s := range by[nodeFSSize] {
			mp := s.Labels["mountpoint"]
			if seen[mp] || skipFS(s.Labels["fstype"], mp) || s.Labels["device_error"] != "" && s.Labels["device_error"] != "0" {
				continue
			}
			if a, ok := av[mp]; ok && s.Value > 0 {
				seen[mp] = true
				disks = append(disks, disk{mp, s.Value, a})
			}
		}
	} else {
		fr := map[string]float64{}
		for _, s := range by[winDiskFree] {
			fr[s.Labels["volume"]] = s.Value
		}
		for _, s := range by[winDiskSize] {
			v := s.Labels["volume"]
			if !driveLetter(v) { // HarddiskVolumeN = partisi sistem/pemulihan tanpa huruf drive
				continue
			}
			if f, ok := fr[v]; ok && s.Value > 0 {
				disks = append(disks, disk{v, s.Value, f})
			}
		}
	}
	sort.Slice(disks, func(i, j int) bool { return disks[i].name < disks[j].name })
	for i, d := range disks {
		if i >= maxDisks {
			break
		}
		if d.avail < 0 || d.avail > d.size {
			continue
		}
		out = append(out, Reading{Key: "px:disk:" + keyPart(d.name), Class: "disk", Label: "Disk " + d.name, Unit: "%", Value: pct((1 - d.avail/d.size) * 100)})
	}

	// Layanan yang diminta
	for i, name := range services {
		if i >= maxServices {
			break
		}
		state := ""
		if kind == "windows" {
			for _, s := range by[winSvcState] {
				if s.Value == 1 && strings.EqualFold(s.Labels["service"], name) {
					state = s.Labels["state"]
				}
			}
		} else {
			unit := name
			if !strings.Contains(unit, ".") {
				unit += ".service"
			}
			for _, s := range by[nodeSystemd] {
				if s.Value == 1 && s.Labels["name"] == unit {
					state = s.Labels["state"]
				}
			}
		}
		r := Reading{Key: "px:svc:" + keyPart(name), Class: "service", Label: "Layanan " + name, Text: state, Status: "ok"}
		switch {
		case state == "":
			r.Text, r.Status = statusMissing, "warn"
		case kind == "windows" && state != "running", kind == "node" && state != "active":
			r.Status = "crit"
		}
		out = append(out, r)
	}
	return out, next, kind
}

func one(list []Sample) float64 {
	if len(list) == 0 {
		return -1
	}
	return list[0].Value
}

var skipTypes = map[string]bool{"tmpfs": true, "devtmpfs": true, "overlay": true, "squashfs": true, "ramfs": true, "nsfs": true,
	"proc": true, "sysfs": true, "autofs": true, "cgroup": true, "cgroup2": true, "rootfs": true, "fuse.lxcfs": true, "iso9660": true}

func skipFS(fstype, mp string) bool {
	if skipTypes[fstype] {
		return true
	}
	for _, p := range []string{"/run", "/proc", "/sys", "/dev", "/var/lib/docker", "/var/lib/kubelet", "/snap", "/boot/efi"} {
		if mp == p || strings.HasPrefix(mp, p+"/") {
			return true
		}
	}
	return false
}

func driveLetter(v string) bool {
	return len(v) == 2 && v[1] == ':' && unicode.IsLetter(rune(v[0]))
}

func keyPart(s string) string {
	var b strings.Builder
	for _, r := range strings.ToLower(s) {
		if r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '.' || r == '-' || r == '_' {
			b.WriteRune(r)
		} else {
			b.WriteRune('_')
		}
	}
	k := b.String()
	if k == "_" || k == "" {
		k = "root"
	}
	if len(k) > 60 {
		k = k[:60]
	}
	return k
}
