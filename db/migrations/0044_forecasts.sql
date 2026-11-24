-- Prediksi habis per sensor (disk penuh, bahan bakar habis), dihitung collector tiap jam dari VictoriaMetrics.
CREATE TABLE IF NOT EXISTS sensor_forecasts (
    device_id   BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    key         VARCHAR(96)  NOT NULL,
    class       VARCHAR(32)  NOT NULL,
    label       VARCHAR(128) NOT NULL,
    current     DOUBLE PRECISION NOT NULL,
    limit_value DOUBLE PRECISION NOT NULL,      -- batas yang dituju (100 untuk disk, 0 untuk bahan bakar)
    per_day     DOUBLE PRECISION NOT NULL,      -- laju perubahan per hari dari regresi
    r2          DOUBLE PRECISION,
    eta         TIMESTAMPTZ,                    -- perkiraan waktu mencapai batas; NULL = tidak menuju batas
    points      INTEGER      NOT NULL,
    computed_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, key)
);
