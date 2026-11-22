-- ANTAREJA NMS — tahap 4b: IPAM & konflik IP

CREATE TABLE subnets (
    id          BIGSERIAL PRIMARY KEY,
    cidr        CIDR         NOT NULL UNIQUE,
    name        VARCHAR(128),
    site_id     BIGINT REFERENCES sites(id) ON DELETE SET NULL,
    vlan        INTEGER CHECK (vlan BETWEEN 1 AND 4094),
    note        TEXT,
    source      VARCHAR(8)   NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'auto')),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Alamat IP milik interface perangkat (ipAddrTable)
CREATE TABLE device_ips (
    device_id   BIGINT  NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    ip          INET    NOT NULL,
    if_index    INTEGER,
    prefix      SMALLINT,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, ip)
);
CREATE INDEX device_ips_ip_idx ON device_ips (ip);

-- Tabel ARP yang dilaporkan router/L3 (reporter)
CREATE TABLE arp_entries (
    reporter_id BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index    INTEGER     NOT NULL,
    ip          INET        NOT NULL,
    mac         VARCHAR(17) NOT NULL,
    first_seen  TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (reporter_id, if_index, ip)
);
CREATE INDEX arp_entries_ip_idx ON arp_entries (ip);
CREATE INDEX arp_entries_mac_idx ON arp_entries (mac);
CREATE INDEX arp_entries_seen_idx ON arp_entries (last_seen);

-- Riwayat pergantian MAC untuk IP yang sama pada router & port yang sama (dasar deteksi konflik)
CREATE TABLE arp_changes (
    id          BIGSERIAL PRIMARY KEY,
    reporter_id BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index    INTEGER     NOT NULL,
    ip          INET        NOT NULL,
    old_mac     VARCHAR(17) NOT NULL,
    new_mac     VARCHAR(17) NOT NULL,
    at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX arp_changes_at_idx ON arp_changes (at DESC);
CREATE INDEX arp_changes_ip_idx ON arp_changes (reporter_id, ip, at DESC);

INSERT INTO settings (key, value) VALUES
    ('ipam.conflict_changes', '3'),   -- minimal pergantian MAC dalam jendela
    ('ipam.conflict_hours', '6')
ON CONFLICT (key) DO NOTHING;
