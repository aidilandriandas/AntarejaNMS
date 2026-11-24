package promx

import (
	"strings"
	"testing"
)

const nodeText = `# TYPE node_cpu_seconds_total counter
node_cpu_seconds_total{cpu="0",mode="idle"} %IDLE%
node_cpu_seconds_total{cpu="0",mode="iowait"} 10
node_cpu_seconds_total{cpu="0",mode="user"} %USER%
node_cpu_seconds_total{cpu="0",mode="system"} 50
node_memory_MemTotal_bytes 8e+09
node_memory_MemAvailable_bytes 2e+09
node_filesystem_size_bytes{device="/dev/sda1",device_error="",fstype="ext4",mountpoint="/"} 1e+11
node_filesystem_avail_bytes{device="/dev/sda1",device_error="",fstype="ext4",mountpoint="/"} 5e+09
node_filesystem_size_bytes{device="tmpfs",device_error="",fstype="tmpfs",mountpoint="/run"} 1e+09
node_filesystem_avail_bytes{device="tmpfs",device_error="",fstype="tmpfs",mountpoint="/run"} 1e+09
node_systemd_unit_state{name="nginx.service",state="active",type="notify"} 1
node_systemd_unit_state{name="nginx.service",state="failed",type="notify"} 0
node_systemd_unit_state{name="mysql.service",state="active",type="simple"} 0
node_systemd_unit_state{name="mysql.service",state="failed",type="simple"} 1
`

func find(rs []Reading, key string) *Reading {
	for i := range rs {
		if rs[i].Key == key {
			return &rs[i]
		}
	}
	return nil
}

func TestComputeNode(t *testing.T) {
	text := func(idle, user string) []Sample {
		s, _ := Parse(strings.NewReader(strings.NewReplacer("%IDLE%", idle, "%USER%", user).Replace(nodeText)), Keep)
		return s
	}
	svc := []string{"nginx", "mysql", "redis"}
	r1, st, kind := Compute(text("1000", "100"), svc, nil)
	if kind != "node" || find(r1, "px:cpu") != nil {
		t.Fatal("pembacaan pertama tanpa CPU", kind)
	}
	// +60 idle, +40 user dalam 100 detik total: CPU 40%
	r2, _, _ := Compute(text("1060", "140"), svc, st)
	if c := find(r2, "px:cpu"); c == nil || *c.Value != 40 {
		t.Fatalf("CPU %+v", c)
	}
	if m := find(r2, "px:mem"); m == nil || *m.Value != 75 {
		t.Fatalf("memori %+v", m)
	}
	if d := find(r2, "px:disk:root"); d == nil || *d.Value != 95 || d.Label != "Disk /" {
		t.Fatalf("disk %+v", d)
	}
	if find(r2, "px:disk:_run") != nil {
		t.Fatal("tmpfs /run harus dilewati")
	}
	if s := find(r2, "px:svc:nginx"); s == nil || s.Status != "ok" || s.Text != "active" {
		t.Fatalf("nginx %+v", s)
	}
	if s := find(r2, "px:svc:mysql"); s == nil || s.Status != "crit" || s.Text != "failed" {
		t.Fatalf("mysql %+v", s)
	}
	if s := find(r2, "px:svc:redis"); s == nil || s.Status != "warn" {
		t.Fatalf("redis %+v", s)
	}
}

func TestComputeWindows(t *testing.T) {
	in := `windows_cpu_time_seconds_total{core="0,0",mode="idle"} 500
windows_cpu_time_seconds_total{core="0,0",mode="user"} 500
windows_memory_physical_total_bytes 1.6e+10
windows_memory_physical_free_bytes 4e+09
windows_logical_disk_size_bytes{volume="C:"} 2e+11
windows_logical_disk_free_bytes{volume="C:"} 1e+11
windows_logical_disk_size_bytes{volume="HarddiskVolume1"} 5e+08
windows_logical_disk_free_bytes{volume="HarddiskVolume1"} 1e+08
windows_service_state{service="mssqlserver",state="running"} 1
windows_service_state{service="mssqlserver",state="stopped"} 0
windows_service_state{service="spooler",state="running"} 0
windows_service_state{service="spooler",state="stopped"} 1
`
	s, _ := Parse(strings.NewReader(in), Keep)
	r, st, kind := Compute(s, []string{"MSSQLSERVER", "Spooler"}, &CPUState{Idle: 400, Total: 800})
	if kind != "windows" || st == nil {
		t.Fatal(kind)
	}
	if c := find(r, "px:cpu"); c == nil || *c.Value != 50 {
		t.Fatalf("CPU %+v", c)
	}
	if m := find(r, "px:mem"); m == nil || *m.Value != 75 {
		t.Fatalf("memori %+v", m)
	}
	if d := find(r, "px:disk:c_"); d == nil || *d.Value != 50 {
		t.Fatalf("disk %+v", r)
	}
	if find(r, "px:disk:harddiskvolume1") != nil {
		t.Fatal("volume tanpa huruf drive dilewati")
	}
	if a, b := find(r, "px:svc:mssqlserver"), find(r, "px:svc:spooler"); a.Status != "ok" || b.Status != "crit" || b.Text != "stopped" {
		t.Fatalf("%+v %+v", a, b)
	}
}

func TestCheckURL(t *testing.T) {
	for u, ok := range map[string]bool{"http://10.0.0.5:9100/metrics": true, "https://srv:9182/metrics": true,
		"ftp://x/metrics": false, "http://admin:rahasia@10.0.0.5:9100/metrics": false, "10.0.0.5:9100": false} {
		if (CheckURL(u) == nil) != ok {
			t.Errorf("%s", u)
		}
	}
}
