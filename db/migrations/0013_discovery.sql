-- ANTAREJA NMS — tahap 6b: discovery jaringan (pindai subnet: ping + SNMP GET grup system, hanya baca)

CREATE TABLE discovery_scans (
    id               BIGSERIAL    PRIMARY KEY,
    cidr             CIDR         NOT NULL,
    site_id          BIGINT       REFERENCES sites(id) ON DELETE SET NULL,   -- site usulan untuk hasil
    snmp_port        INTEGER      NOT NULL DEFAULT 161,
    communities_enc  TEXT,          -- daftar community v2c (JSON, terenkripsi); dihapus otomatis 7 hari setelah selesai
    snmp_all         BOOLEAN      NOT NULL DEFAULT FALSE,   -- coba SNMP juga ke alamat yang tidak menjawab ping
    status           VARCHAR(10)  NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'cancelled')),
    total            INTEGER      NOT NULL DEFAULT 0,
    progress         INTEGER      NOT NULL DEFAULT 0,
    found            INTEGER      NOT NULL DEFAULT 0,
    error            VARCHAR(255),
    created_by       BIGINT,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    started_at       TIMESTAMPTZ,
    finished_at      TIMESTAMPTZ
);
CREATE INDEX discovery_scans_status_idx ON discovery_scans (status, created_at);

CREATE TABLE discovery_results (
    id             BIGSERIAL    PRIMARY KEY,
    scan_id        BIGINT       NOT NULL REFERENCES discovery_scans(id) ON DELETE CASCADE,
    ip             INET         NOT NULL,
    ping_ok        BOOLEAN      NOT NULL,
    rtt_ms         REAL,
    snmp_ok        BOOLEAN      NOT NULL DEFAULT FALSE,
    community_idx  SMALLINT,     -- indeks community yang dijawab (bukan nilainya)
    sys_name       VARCHAR(255),
    sys_descr      TEXT,
    sys_object_id  VARCHAR(255),
    category       VARCHAR(32)  NOT NULL DEFAULT 'other',   -- tebakan dari sysObjectID / sysDescr
    vendor         VARCHAR(64),
    device_id      BIGINT       REFERENCES devices(id) ON DELETE SET NULL,  -- sudah terdaftar / sudah ditambahkan
    state          VARCHAR(8)   NOT NULL DEFAULT 'new' CHECK (state IN ('new', 'exists', 'added', 'ignored')),
    UNIQUE (scan_id, ip)
);
