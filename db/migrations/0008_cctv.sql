-- ANTAREJA NMS — tahap 4c: CCTV live view

CREATE TABLE cameras (
    id            BIGSERIAL PRIMARY KEY,
    name          VARCHAR(128) NOT NULL,
    device_id     BIGINT REFERENCES devices(id) ON DELETE SET NULL,  -- kamera atau NVR yang dipantau
    site_id       BIGINT REFERENCES sites(id) ON DELETE SET NULL,
    host          VARCHAR(255) NOT NULL,
    rtsp_port     INTEGER      NOT NULL DEFAULT 554,
    channel       INTEGER      NOT NULL DEFAULT 1 CHECK (channel BETWEEN 1 AND 256),
    path_main     VARCHAR(255) NOT NULL DEFAULT '/Streaming/Channels/{ch}01',  -- format Hikvision
    path_sub      VARCHAR(255) NOT NULL DEFAULT '/Streaming/Channels/{ch}02',
    username      VARCHAR(64),
    password_enc  TEXT,         -- terenkripsi (kunci ANTAREJA_APP_KEY); tidak pernah dikirim ke browser
    enabled       BOOLEAN      NOT NULL DEFAULT TRUE,
    sort          INTEGER      NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX cameras_site_idx ON cameras (site_id);
