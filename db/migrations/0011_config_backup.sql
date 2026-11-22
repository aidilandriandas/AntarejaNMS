-- ANTAREJA NMS — tahap 5c: backup konfigurasi perangkat via SSH (hanya baca)

ALTER TABLE devices ADD COLUMN cfg_type VARCHAR(10) CHECK (cfg_type IN ('mikrotik', 'cisco'));  -- kosong = tidak di-backup
ALTER TABLE devices ADD COLUMN ssh_port INTEGER NOT NULL DEFAULT 22;
ALTER TABLE devices ADD COLUMN ssh_user VARCHAR(64);
ALTER TABLE devices ADD COLUMN ssh_password_enc TEXT;     -- terenkripsi
ALTER TABLE devices ADD COLUMN ssh_hostkey VARCHAR(128);  -- sidik host key (dicatat saat pertama terhubung)
ALTER TABLE devices ADD COLUMN cfg_last_at TIMESTAMPTZ;   -- percobaan terakhir
ALTER TABLE devices ADD COLUMN cfg_error VARCHAR(255);
ALTER TABLE devices ADD COLUMN cfg_requested BOOLEAN NOT NULL DEFAULT FALSE;  -- diminta dari web

CREATE TABLE device_configs (
    id           BIGSERIAL PRIMARY KEY,
    device_id    BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    taken_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    sha256       CHAR(64)     NOT NULL,
    lines        INTEGER      NOT NULL,
    size_bytes   INTEGER      NOT NULL,
    content_enc  TEXT         NOT NULL,    -- isi konfigurasi terenkripsi (bisa memuat data sensitif)
    diff_enc     TEXT,                     -- diff terhadap versi sebelumnya (terenkripsi)
    added        INTEGER      NOT NULL DEFAULT 0,
    removed      INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX device_configs_device_idx ON device_configs (device_id, taken_at DESC);
