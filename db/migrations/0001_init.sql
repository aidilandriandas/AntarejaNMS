-- ANTAREJA NMS — skema awal (tahap 1a)

CREATE TABLE sites (
    id          BIGSERIAL PRIMARY KEY,
    code        VARCHAR(32)  NOT NULL UNIQUE,
    name        VARCHAR(128) NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE devices (
    id                  BIGSERIAL PRIMARY KEY,
    name                VARCHAR(128) NOT NULL,
    host                VARCHAR(255) NOT NULL,           -- IP atau nama DNS
    site_id             BIGINT REFERENCES sites(id) ON DELETE SET NULL,
    category            VARCHAR(32)  NOT NULL DEFAULT 'other',
    enabled             BOOLEAN      NOT NULL DEFAULT TRUE,

    ping_enabled        BOOLEAN      NOT NULL DEFAULT TRUE,
    snmp_enabled        BOOLEAN      NOT NULL DEFAULT FALSE,
    snmp_version        VARCHAR(3)   NOT NULL DEFAULT '2c' CHECK (snmp_version IN ('1', '2c', '3')),
    snmp_port           INTEGER      NOT NULL DEFAULT 161,
    -- Kredensial terenkripsi (payload Laravel Crypt, kunci ANTAREJA_APP_KEY). Jangan pernah disimpan polos.
    snmp_community_enc  TEXT,
    snmp_v3_user        VARCHAR(64),
    snmp_v3_level       VARCHAR(12)  CHECK (snmp_v3_level IN ('noAuthNoPriv', 'authNoPriv', 'authPriv')),
    snmp_v3_auth_proto  VARCHAR(8)   CHECK (snmp_v3_auth_proto IN ('MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512')),
    snmp_v3_auth_enc    TEXT,
    snmp_v3_priv_proto  VARCHAR(8)   CHECK (snmp_v3_priv_proto IN ('DES', 'AES', 'AES192', 'AES256')),
    snmp_v3_priv_enc    TEXT,

    -- Status terkini (ditulis collector)
    status              VARCHAR(8)   NOT NULL DEFAULT 'unknown' CHECK (status IN ('up', 'down', 'unknown')),
    status_reason       VARCHAR(255),
    status_changed_at   TIMESTAMPTZ,
    fail_streak         INTEGER      NOT NULL DEFAULT 0,      -- putaran gagal berturut-turut (ping, atau SNMP bila ping mati)
    last_rtt_ms         REAL,
    last_loss_pct       REAL,
    last_ping_at        TIMESTAMPTZ,
    snmp_ok             BOOLEAN,
    snmp_error          VARCHAR(255),
    last_snmp_at        TIMESTAMPTZ,
    sys_name            VARCHAR(255),
    sys_descr           TEXT,
    sys_object_id       VARCHAR(255),
    sys_location        VARCHAR(255),
    uptime_seconds      BIGINT,

    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX devices_status_idx ON devices (status) WHERE enabled;
CREATE INDEX devices_site_idx ON devices (site_id);

CREATE TABLE interfaces (
    device_id     BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index      INTEGER      NOT NULL,
    name          VARCHAR(255),
    alias         VARCHAR(255),
    descr         VARCHAR(255),
    if_type       INTEGER,
    speed_bps     BIGINT,
    admin_up      BOOLEAN,
    oper_up       BOOLEAN,
    mac           VARCHAR(17),
    last_change_at TIMESTAMPTZ,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, if_index)
);

CREATE TABLE events (
    id          BIGSERIAL PRIMARY KEY,
    device_id   BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    kind        VARCHAR(16)  NOT NULL,
    message     VARCHAR(500) NOT NULL,
    duration_s  BIGINT,
    at          TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX events_at_idx ON events (at DESC);
CREATE INDEX events_device_idx ON events (device_id, at DESC);
