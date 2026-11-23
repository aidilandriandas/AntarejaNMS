package poller

// Router/L3 yang tabel ARP-nya dibaca untuk IPAM.
var arpCategories = map[string]bool{"core": true, "distribution": true, "firewall": true}
