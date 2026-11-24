-- Indeks untuk pencocokan tetangga LLDP/CDP ke perangkat (uji beban 1.000 perangkat: tanpa indeks ini
-- pencocokan ulang memindai semua perangkat dan interface untuk setiap tetangga yang belum dikenal).
CREATE INDEX IF NOT EXISTS devices_host_idx ON devices (host);
CREATE INDEX IF NOT EXISTS devices_sysname_lower_idx ON devices (lower(sys_name)) WHERE sys_name IS NOT NULL;
CREATE INDEX IF NOT EXISTS devices_sysname_short_idx ON devices (lower(split_part(sys_name, '.', 1))) WHERE sys_name IS NOT NULL;
CREATE INDEX IF NOT EXISTS devices_name_lower_idx ON devices (lower(name));
CREATE INDEX IF NOT EXISTS interfaces_mac_lower_idx ON interfaces (lower(mac)) WHERE mac IS NOT NULL;
