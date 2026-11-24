-- Ringkasan NetFlow/IPFIX per 5 menit: per perangkat eksportir, port (ifIndex) dan arah, 50 percakapan terbesar
-- (IP sumber, IP tujuan, protokol, port layanan) + satu baris "lainnya" (src/dst NULL). Retensi: flow.keep_days (7).
CREATE TABLE IF NOT EXISTS flow_stats (
    bucket     TIMESTAMPTZ NOT NULL,
    device_id  BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index   BIGINT      NOT NULL,
    dir        CHAR(1)     NOT NULL CHECK (dir IN ('i', 'o')),
    src        INET,
    dst        INET,
    proto      SMALLINT    NOT NULL DEFAULT 0,
    port       INTEGER     NOT NULL DEFAULT 0,
    bytes      BIGINT      NOT NULL,
    packets    BIGINT      NOT NULL
);
CREATE INDEX IF NOT EXISTS flow_stats_port_idx ON flow_stats (device_id, if_index, bucket);
CREATE INDEX IF NOT EXISTS flow_stats_bucket_idx ON flow_stats (bucket);
