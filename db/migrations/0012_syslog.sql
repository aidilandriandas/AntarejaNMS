-- ANTAREJA NMS — tahap 6a: penerima syslog

CREATE EXTENSION IF NOT EXISTS pg_trgm;  -- pencarian teks log (ILIKE) yang cepat

CREATE TABLE syslog (
    id           BIGSERIAL    PRIMARY KEY,
    received_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),   -- waktu terima (jam perangkat sering salah, tidak dipakai)
    device_id    BIGINT       REFERENCES devices(id) ON DELETE SET NULL,  -- kosong = pengirim belum terdaftar
    source       INET         NOT NULL,
    facility     SMALLINT,                               -- 0–23, kosong bila pesan tanpa PRI
    severity     SMALLINT     NOT NULL,                  -- 0 emerg … 7 debug
    host         VARCHAR(128),
    app          VARCHAR(64),
    message      TEXT         NOT NULL
);
CREATE INDEX syslog_time_idx   ON syslog (received_at DESC);
CREATE INDEX syslog_device_idx ON syslog (device_id, received_at DESC);
CREATE INDEX syslog_source_idx ON syslog (source, received_at DESC);
CREATE INDEX syslog_msg_trgm   ON syslog USING gin (message gin_trgm_ops);

INSERT INTO settings (key, value) VALUES
    ('syslog.keep_days', '14'),       -- lama penyimpanan log
    ('syslog.event_severity', '2')    -- tingkat ≤ ini (0 emerg, 1 alert, 2 crit) dicatat juga sebagai kejadian perangkat
ON CONFLICT (key) DO NOTHING;
