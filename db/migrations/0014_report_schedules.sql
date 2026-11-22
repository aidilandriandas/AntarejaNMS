-- ANTAREJA NMS — tahap 6c: laporan terjadwal (ringkasan harian/mingguan/bulanan lewat kanal notifikasi)

CREATE TABLE report_schedules (
    id            BIGSERIAL    PRIMARY KEY,
    name          VARCHAR(128) NOT NULL,
    frequency     VARCHAR(8)   NOT NULL CHECK (frequency IN ('daily', 'weekly', 'monthly')),
    send_hour     SMALLINT     NOT NULL DEFAULT 7 CHECK (send_hour BETWEEN 0 AND 23),   -- jam kirim (zona waktu server)
    channel_id    BIGINT       NOT NULL REFERENCES notify_channels(id) ON DELETE CASCADE,
    site_id       BIGINT       REFERENCES sites(id) ON DELETE CASCADE,                  -- kosong = semua site
    enabled       BOOLEAN      NOT NULL DEFAULT TRUE,
    requested     BOOLEAN      NOT NULL DEFAULT FALSE,   -- "kirim sekarang" dari web
    last_key      VARCHAR(16),                           -- periode terakhir yang terkirim (mis. 2026-11-21, 2026-11-16, 2026-10)
    last_sent_at  TIMESTAMPTZ,
    last_error    VARCHAR(255),
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
