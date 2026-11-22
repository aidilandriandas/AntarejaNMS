-- ANTAREJA NMS: trigger custom (aturan problem buatan engineer)
-- Satu trigger = satu metrik yang sudah dikumpulkan collector, dibandingkan dengan ambang, harus bertahan N menit,
-- opsional hanya pada hari & jam tertentu. Problem yang dihasilkan berjenis 'custom' dengan sensor_key 'trg:<id>[:...]'.

CREATE TABLE triggers (
    id            BIGSERIAL    PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    enabled       BOOLEAN      NOT NULL DEFAULT TRUE,
    severity      VARCHAR(10)  NOT NULL CHECK (severity IN ('info', 'warning', 'average', 'high', 'disaster')),
    metric        VARCHAR(12)  NOT NULL CHECK (metric IN ('latency', 'loss', 'sensor', 'port_in', 'port_out', 'port_util')),
    sensor_class  VARCHAR(24),                     -- metrik sensor: kelas sensor (temperature, signal, ...)
    match_text    VARCHAR(120),                    -- saring nama sensor / nama atau keterangan port (berisi teks ini)
    op            VARCHAR(2)   NOT NULL CHECK (op IN ('>', '>=', '<', '<=')),
    threshold     DOUBLE PRECISION NOT NULL,       -- latensi ms, loss %, port Mbps / %, sensor dalam satuannya
    duration_min  INTEGER      NOT NULL DEFAULT 5 CHECK (duration_min BETWEEN 0 AND 1440),
    scope         VARCHAR(8)   NOT NULL DEFAULT 'all' CHECK (scope IN ('all', 'site', 'category', 'devices')),
    site_id       BIGINT       REFERENCES sites(id) ON DELETE CASCADE,
    category      VARCHAR(24),
    days          SMALLINT     NOT NULL DEFAULT 127 CHECK (days BETWEEN 1 AND 127),  -- bit 0 = Senin ... bit 6 = Minggu
    start_min     INTEGER      CHECK (start_min BETWEEN 0 AND 1439),                -- jam aktif (menit sejak 00:00), kosong = sepanjang hari
    end_min       INTEGER      CHECK (end_min BETWEEN 1 AND 1440),
    note          VARCHAR(500),
    created_by    BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (metric <> 'sensor' OR sensor_class IS NOT NULL),
    CHECK ((start_min IS NULL) = (end_min IS NULL))
);

CREATE TABLE trigger_devices (
    trigger_id BIGINT NOT NULL REFERENCES triggers(id) ON DELETE CASCADE,
    device_id  BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    PRIMARY KEY (trigger_id, device_id)
);

-- Trigger yang dihapus: problem-nya ikut ditutup oleh engine (tidak ada lagi kondisi yang menghasilkannya).
