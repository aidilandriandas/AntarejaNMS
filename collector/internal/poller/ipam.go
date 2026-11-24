package poller

// Router/L3 yang tabel ARP-nya dibaca untuk IPAM.
var arpCategories = map[string]bool{"core": true, "distribution": true, "firewall": true}

// Switch yang tabel MAC-nya dibaca untuk lacak MAC ke port.
var fdbCategories = map[string]bool{"core": true, "distribution": true, "access": true}
