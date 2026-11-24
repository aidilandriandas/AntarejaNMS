-- Lacak MAC ke port switch: tabel MAC (FDB) tiap switch, lokasi tepi tiap MAC, dan riwayat pindah port.
CREATE TABLE IF NOT EXISTS fdb_entries (
    device_id   BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    mac         VARCHAR(17) NOT NULL,
    vlan        INTEGER     NOT NULL DEFAULT 0,      -- FDB id Q-BRIDGE (umumnya = VLAN); 0 bila BRIDGE-MIB
    if_index    INTEGER     NOT NULL,
    first_seen  TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, mac, vlan)
);
CREATE INDEX IF NOT EXISTS fdb_entries_mac_idx ON fdb_entries (mac);
CREATE INDEX IF NOT EXISTS fdb_entries_port_idx ON fdb_entries (device_id, if_index);

-- Port tepi tempat MAC terpasang (bukan uplink), dihitung ulang tiap putaran SNMP.
CREATE TABLE IF NOT EXISTS mac_locations (
    mac         VARCHAR(17) PRIMARY KEY,
    device_id   BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index    INTEGER     NOT NULL,
    vlan        INTEGER     NOT NULL DEFAULT 0,
    first_seen  TIMESTAMPTZ NOT NULL DEFAULT now(),   -- pertama terlihat di port ini
    last_seen   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS mac_locations_port_idx ON mac_locations (device_id, if_index);

CREATE TABLE IF NOT EXISTS mac_moves (
    id              BIGSERIAL PRIMARY KEY,
    mac             VARCHAR(17) NOT NULL,
    from_device_id  BIGINT REFERENCES devices(id) ON DELETE SET NULL,
    from_if_index   INTEGER,
    to_device_id    BIGINT REFERENCES devices(id) ON DELETE SET NULL,
    to_if_index     INTEGER,
    at              TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS mac_moves_mac_idx ON mac_moves (mac, at DESC);
CREATE INDEX IF NOT EXISTS mac_moves_at_idx ON mac_moves (at DESC);
