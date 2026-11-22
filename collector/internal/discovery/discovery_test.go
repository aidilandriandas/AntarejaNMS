package discovery

import "testing"

func TestHosts(t *testing.T) {
	cases := map[string]int{"10.0.0.0/24": 254, "10.0.0.5/30": 2, "10.0.0.7/32": 1, "10.0.0.0/31": 2, "10.0.0.0/22": 1022}
	for c, n := range cases {
		h, err := Hosts(c)
		if err != nil || len(h) != n {
			t.Errorf("%s: %d %v", c, len(h), err)
		}
	}
	if h, _ := Hosts("192.168.1.77/24"); h[0].String() != "192.168.1.1" || h[len(h)-1].String() != "192.168.1.254" {
		t.Errorf("batas salah: %v %v", h[0], h[len(h)-1])
	}
	for _, bad := range []string{"10.0.0.0/21", "fe80::/64", "10.0.0.0", "abc"} {
		if _, err := Hosts(bad); err == nil {
			t.Errorf("%s seharusnya ditolak", bad)
		}
	}
}

func TestClassify(t *testing.T) {
	cases := []struct{ oid, descr, name, vendor, cat string }{
		{".1.3.6.1.4.1.14988.1", "RouterOS CCR2004-16G-2S+", "core-pit-a", "MikroTik", "distribution"},
		{".1.3.6.1.4.1.14988.1", "RouterOS CRS326-24G-2S+", "", "MikroTik", "access"},
		{".1.3.6.1.4.1.14988.1", "RouterOS LHG 5 ac", "ptp-pit-b", "MikroTik", "ptp"},
		{".1.3.6.1.4.1.9.1.2134", "Cisco IOS Software, Catalyst L3 Switch Software (CAT9K_IOSXE)", "", "Cisco", "access"},
		{".1.3.6.1.4.1.9.1.1", "Cisco IOS Software, ISR Software (X86_64_LINUX_IOSD-UNIVERSALK9-M)", "", "Cisco", "core"},
		{".1.3.6.1.4.1.9.1.745", "Cisco Adaptive Security Appliance Version 9.8", "", "Cisco", "firewall"},
		{".1.3.6.1.4.1.41112.1.4", "Linux 2.6.32.71 #1 Sat Jan 1 airOS", "", "Ubiquiti", "ptp"},
		{".1.3.6.1.4.1.39165.1.1", "DS-7716NI-K4", "nvr-pit", "Hikvision", "nvr"},
		{".1.3.6.1.4.1.39165.1.1", "DS-2CD2143G2-I", "", "Hikvision", "cctv"},
		{".1.3.6.1.4.1.318.1.3.27", "APC Web/SNMP Management Card", "", "APC", "ups"},
		{".1.3.6.1.4.1.12356.101.1.1", "FortiGate-100F", "", "Fortinet", "firewall"},
		{".1.3.6.1.4.1.8072.3.2.10", "Linux srv-nms 6.8.0-45-generic", "", "Net-SNMP", "server"},
		{"", "", "", "", "other"},
	}
	for _, c := range cases {
		v, k := Classify(c.oid, c.descr, c.name)
		if v != c.vendor || k != c.cat {
			t.Errorf("%s %q → %s/%s, harap %s/%s", c.oid, c.descr, v, k, c.vendor, c.cat)
		}
	}
}
