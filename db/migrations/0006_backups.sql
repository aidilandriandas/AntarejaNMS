-- ANTAREJA NMS — tahap 4a: backup NMS & SLA

CREATE TABLE backups (
    id            BIGSERIAL PRIMARY KEY,
    kind          VARCHAR(10)  NOT NULL CHECK (kind IN ('scheduled', 'manual')),
    status        VARCHAR(10)  NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'running', 'ok', 'failed', 'deleted')),
    file          VARCHAR(255),
    size_bytes    BIGINT,
    error         VARCHAR(500),
    requested_by  BIGINT REFERENCES users(id) ON DELETE SET NULL,
    requested_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    started_at    TIMESTAMPTZ,
    finished_at   TIMESTAMPTZ
);
CREATE INDEX backups_requested_idx ON backups (requested_at DESC);

INSERT INTO settings (key, value) VALUES
    ('backup.time', '"01:30"'),      -- jam backup harian (zona waktu server)
    ('backup.keep', '14'),           -- jumlah backup yang disimpan
    ('backup.enabled', 'true'),
    ('sla.target_pct', '99.5')       -- target ketersediaan bulanan
ON CONFLICT (key) DO NOTHING;
