-- Collector per site: collector kecil di site tambang memantau perangkat lokal dan mengirim hasil ke pusat
-- lewat HTTPS. Bila jalur ke pusat putus, hasil diantrekan di site lalu dikirim saat jalur pulih.
CREATE TABLE IF NOT EXISTS site_collectors (
    id             BIGSERIAL PRIMARY KEY,
    name           VARCHAR(64)  NOT NULL UNIQUE,
    site_id        BIGINT REFERENCES sites(id) ON DELETE SET NULL,
    token_hash     CHAR(64)     NOT NULL UNIQUE,   -- sha256 token; token asli hanya ditampilkan sekali
    token_hint     VARCHAR(16)  NOT NULL,          -- awalan token untuk dikenali di layar
    enabled        BOOLEAN      NOT NULL DEFAULT true,
    notes          TEXT,
    last_seen_at   TIMESTAMPTZ,                    -- terakhir menghubungi pusat
    last_ip        VARCHAR(45),
    version        VARCHAR(32),
    queued         INTEGER,                        -- antrean hasil di site saat terakhir melapor
    newest_sample_at TIMESTAMPTZ,                  -- hasil pengukuran terbaru yang sudah diterima
    last_ping_at   TIMESTAMPTZ,                    -- putaran ping terakhir yang diterapkan (cegah kiriman ganda)
    clock_skew_s   INTEGER,                        -- selisih jam site terhadap pusat saat terakhir melapor
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- NULL = dipantau collector pusat. Collector site dihapus: perangkatnya kembali dipantau pusat.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS collector_id BIGINT REFERENCES site_collectors(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS devices_collector_idx ON devices (collector_id) WHERE collector_id IS NOT NULL;
