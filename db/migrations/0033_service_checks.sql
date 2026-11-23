-- Cek layanan: HTTP(S), port TCP, dan DNS dijalankan collector pusat secara berkala.
-- Setiap cek melekat ke satu perangkat (server/perangkat yang menjalankan layanan) supaya maintenance,
-- korelasi induk, notifikasi, insiden, dan peta dampak berlaku seperti problem lain.
CREATE TABLE IF NOT EXISTS service_checks (
    id               BIGSERIAL PRIMARY KEY,
    name             VARCHAR(128) NOT NULL,
    device_id        BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    type             VARCHAR(8)   NOT NULL CHECK (type IN ('http', 'tcp', 'dns')),
    url              VARCHAR(500),                 -- http
    port             INTEGER CHECK (port BETWEEN 1 AND 65535),   -- tcp
    query            VARCHAR(255),                 -- dns: nama yang ditanyakan ke perangkat ini
    expect_status    VARCHAR(32),                  -- http, mis. "200-399" atau "200,204"
    expect_text      VARCHAR(200),                 -- http: teks yang wajib ada di 64 KB pertama
    expect_answer    VARCHAR(255),                 -- dns: IP yang wajib ada di jawaban
    verify_tls       BOOLEAN      NOT NULL DEFAULT true,
    interval_s       INTEGER      NOT NULL DEFAULT 60 CHECK (interval_s BETWEEN 30 AND 3600),
    timeout_s        INTEGER      NOT NULL DEFAULT 10 CHECK (timeout_s BETWEEN 1 AND 60),
    slow_ms          INTEGER CHECK (slow_ms > 0),  -- di atas ini: lambat (problem Warning)
    cert_warn_days   INTEGER CHECK (cert_warn_days BETWEEN 1 AND 365),
    severity         VARCHAR(10)  NOT NULL DEFAULT 'high' CHECK (severity IN ('info', 'warning', 'average', 'high', 'disaster')),
    enabled          BOOLEAN      NOT NULL DEFAULT true,
    status           VARCHAR(8)   NOT NULL DEFAULT 'unknown' CHECK (status IN ('up', 'slow', 'down', 'unknown')),
    fail_streak      INTEGER      NOT NULL DEFAULT 0,
    last_ms          REAL,
    last_error       VARCHAR(300),
    last_at          TIMESTAMPTZ,
    status_changed_at TIMESTAMPTZ,
    cert_expires_at  TIMESTAMPTZ,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK ((type = 'http' AND url IS NOT NULL) OR (type = 'tcp' AND port IS NOT NULL) OR (type = 'dns' AND query IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS service_checks_device_idx ON service_checks (device_id);
