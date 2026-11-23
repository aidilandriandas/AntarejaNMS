-- ANTAREJA NMS: deteksi anomali
-- Collector membandingkan rata-rata 15 menit terakhir dengan nilai di jam yang sama 14 hari ke belakang
-- (median dan MAD). Yang menyimpang jauh dan bertahan dua putaran dicatat di sini.

CREATE TABLE anomalies (
    id            BIGSERIAL    PRIMARY KEY,
    device_id     BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    metric        VARCHAR(10)  NOT NULL CHECK (metric IN ('latency', 'port_in', 'port_out')),
    if_index      INTEGER,
    if_name       VARCHAR(255),
    direction     VARCHAR(4)   NOT NULL CHECK (direction IN ('up', 'down')),
    value         DOUBLE PRECISION NOT NULL,     -- nilai terakhir (ms atau bit/detik)
    baseline      DOUBLE PRECISION NOT NULL,     -- median jam yang sama 14 hari
    spread        DOUBLE PRECISION NOT NULL,     -- sebaran normal (1,4826 x MAD, dengan batas bawah)
    score         DOUBLE PRECISION NOT NULL,     -- (nilai - median) / sebaran, terbesar selama anomali
    started_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    ended_at      TIMESTAMPTZ,
    dismissed_by  BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    dismiss_note  VARCHAR(255)
);
CREATE UNIQUE INDEX anomalies_open_key ON anomalies (device_id, metric, COALESCE(if_index, -1)) WHERE ended_at IS NULL;
CREATE INDEX anomalies_started ON anomalies (started_at DESC);

-- "Tandai wajar": anomali yang sama tidak dicatat lagi sampai waktu ini (mis. backup malam yang memang besar)
CREATE TABLE anomaly_mutes (
    device_id   BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    metric      VARCHAR(10)  NOT NULL,
    if_index    INTEGER      NOT NULL DEFAULT -1,
    until       TIMESTAMPTZ  NOT NULL,
    created_by  BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    PRIMARY KEY (device_id, metric, if_index)
);

INSERT INTO settings (key, value) VALUES
    ('anomaly.enabled', 'true'),
    ('anomaly.problem_severity', '"warning"')   -- off, info, warning, average: anomali terbuka ikut jadi problem
ON CONFLICT (key) DO NOTHING;
