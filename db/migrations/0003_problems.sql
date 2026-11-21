-- ANTAREJA NMS — tahap 2a: problem, maintenance, notifikasi, ambang, dependensi perangkat

-- Perangkat induk (uplink). Bila induk down, problem anak ditandai "terdampak" dan tidak dinotifikasi.
ALTER TABLE devices ADD COLUMN parent_id BIGINT REFERENCES devices(id) ON DELETE SET NULL;
ALTER TABLE devices ADD CONSTRAINT devices_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id);

-- Laju terkini per port (dihitung collector dari selisih counter antar putaran SNMP).
ALTER TABLE interfaces ADD COLUMN in_bps DOUBLE PRECISION;
ALTER TABLE interfaces ADD COLUMN out_bps DOUBLE PRECISION;
ALTER TABLE interfaces ADD COLUMN util_pct REAL;
-- Port yang dipantau (uplink, link radio, port server penting): problem bila putus / penuh.
-- Disimpan terpisah agar pilihan tidak hilang saat collector membaca ulang daftar port.
CREATE TABLE monitored_ports (
    device_id  BIGINT  NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index   INTEGER NOT NULL,
    PRIMARY KEY (device_id, if_index)
);

CREATE TABLE settings (
    key         VARCHAR(64) PRIMARY KEY,
    value       JSONB       NOT NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
INSERT INTO settings (key, value) VALUES
    ('threshold.latency_ms', '150'),      -- rata-rata 5 menit
    ('threshold.loss_pct', '20'),         -- rata-rata 5 menit
    ('threshold.util_pct', '90'),         -- port dipantau
    ('threshold.snmp_fail_cycles', '2');  -- putaran SNMP gagal berturut-turut sebelum jadi problem

CREATE TABLE problems (
    id            BIGSERIAL PRIMARY KEY,
    device_id     BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    kind          VARCHAR(16)  NOT NULL,     -- down, latency, loss, snmp, port_down, port_util
    if_index      INTEGER,                   -- untuk problem port
    severity      VARCHAR(10)  NOT NULL CHECK (severity IN ('info', 'warning', 'average', 'high', 'disaster')),
    title         VARCHAR(255) NOT NULL,
    detail        VARCHAR(500),
    last_value    REAL,
    started_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    resolved_at   TIMESTAMPTZ,
    acked_at      TIMESTAMPTZ,
    acked_by      BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ack_note      VARCHAR(500),
    suppressed    VARCHAR(16)  CHECK (suppressed IN ('maintenance', 'parent')),
    suppressed_note VARCHAR(255)
);
-- Satu problem terbuka per kondisi
CREATE UNIQUE INDEX problems_open_key ON problems (device_id, kind, COALESCE(if_index, -1)) WHERE resolved_at IS NULL;
CREATE INDEX problems_open_idx ON problems (started_at DESC) WHERE resolved_at IS NULL;
CREATE INDEX problems_resolved_idx ON problems (resolved_at DESC) WHERE resolved_at IS NOT NULL;

CREATE TABLE maintenances (
    id          BIGSERIAL PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    note        TEXT,
    scope       VARCHAR(8)   NOT NULL CHECK (scope IN ('all', 'site', 'devices')),
    site_id     BIGINT REFERENCES sites(id) ON DELETE CASCADE,
    starts_at   TIMESTAMPTZ  NOT NULL,
    ends_at     TIMESTAMPTZ  NOT NULL CHECK (ends_at > starts_at),
    created_by  BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_devices (
    maintenance_id BIGINT NOT NULL REFERENCES maintenances(id) ON DELETE CASCADE,
    device_id      BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    PRIMARY KEY (maintenance_id, device_id)
);
CREATE INDEX maintenances_window_idx ON maintenances (starts_at, ends_at);

-- Kanal notifikasi. delay_minutes > 0 = eskalasi: dikirim hanya bila problem masih terbuka & belum di-ACK selama itu.
CREATE TABLE notify_channels (
    id             BIGSERIAL PRIMARY KEY,
    name           VARCHAR(128) NOT NULL,
    type           VARCHAR(10)  NOT NULL CHECK (type IN ('telegram', 'email', 'webhook')),
    config_enc     TEXT         NOT NULL,      -- JSON terenkripsi (token bot, SMTP, URL) — tidak pernah polos
    min_severity   VARCHAR(10)  NOT NULL DEFAULT 'average' CHECK (min_severity IN ('info', 'warning', 'average', 'high', 'disaster')),
    delay_minutes  INTEGER      NOT NULL DEFAULT 0 CHECK (delay_minutes BETWEEN 0 AND 1440),
    notify_resolve BOOLEAN      NOT NULL DEFAULT TRUE,
    site_id        BIGINT REFERENCES sites(id) ON DELETE CASCADE,  -- kosong = semua site
    enabled        BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE notifications (
    id          BIGSERIAL PRIMARY KEY,
    problem_id  BIGINT REFERENCES problems(id) ON DELETE CASCADE,
    channel_id  BIGINT NOT NULL REFERENCES notify_channels(id) ON DELETE CASCADE,
    kind        VARCHAR(8)  NOT NULL CHECK (kind IN ('open', 'resolve', 'test', 'summary')),
    status      VARCHAR(8)  NOT NULL CHECK (status IN ('sent', 'failed')),
    error       VARCHAR(500),
    at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notifications_once ON notifications (problem_id, channel_id, kind) WHERE problem_id IS NOT NULL AND status = 'sent';
CREATE INDEX notifications_at_idx ON notifications (at DESC);
