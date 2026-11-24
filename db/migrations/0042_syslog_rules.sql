-- Aturan pola syslog: teks atau regex (RE2), N kemunculan dalam jendela waktu per perangkat = problem "log".
CREATE TABLE IF NOT EXISTS syslog_rules (
    id           BIGSERIAL PRIMARY KEY,
    name         VARCHAR(80)  NOT NULL,
    pattern      VARCHAR(300) NOT NULL,
    is_regex     BOOLEAN      NOT NULL DEFAULT FALSE,
    max_severity SMALLINT     NOT NULL DEFAULT 7 CHECK (max_severity BETWEEN 0 AND 7), -- hanya log dengan tingkat <= ini
    app          VARCHAR(64),                       -- program pengirim; kosong = semua
    site_id      BIGINT REFERENCES sites(id) ON DELETE CASCADE,
    device_id    BIGINT REFERENCES devices(id) ON DELETE CASCADE,
    min_count    INTEGER      NOT NULL DEFAULT 1 CHECK (min_count BETWEEN 1 AND 10000),
    window_s     INTEGER      NOT NULL DEFAULT 300 CHECK (window_s BETWEEN 60 AND 86400),
    clear_s      INTEGER      NOT NULL DEFAULT 900 CHECK (clear_s BETWEEN 60 AND 604800), -- pulih bila tidak cocok lagi selama ini
    severity     VARCHAR(10)  NOT NULL DEFAULT 'warning' CHECK (severity IN ('info', 'warning', 'average', 'high', 'disaster')),
    enabled      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS syslog_rule_hits (
    rule_id       BIGINT       NOT NULL REFERENCES syslog_rules(id) ON DELETE CASCADE,
    device_id     BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    fired_at      TIMESTAMPTZ  NOT NULL,
    last_match_at TIMESTAMPTZ  NOT NULL,
    hits          INTEGER      NOT NULL DEFAULT 0, -- kemunculan dalam jendela saat terpicu
    last_message  VARCHAR(500),
    PRIMARY KEY (rule_id, device_id)
);
