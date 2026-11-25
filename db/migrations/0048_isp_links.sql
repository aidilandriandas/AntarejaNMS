-- Link ISP untuk laporan SLA: perangkat yang di-ping (gateway/ujung ISP) beserta target kontrak.
-- Ketersediaan dihitung dari sampel ping: sampel down atau loss >= loss_down_pct dihitung gangguan.
CREATE TABLE IF NOT EXISTS isp_links (
    id                BIGSERIAL PRIMARY KEY,
    name              VARCHAR(80)  NOT NULL UNIQUE,     -- mis. "Metro E PLJ"
    provider          VARCHAR(80),
    device_id         BIGINT       NOT NULL UNIQUE REFERENCES devices(id) ON DELETE CASCADE,
    target_pct        NUMERIC(6,3) NOT NULL DEFAULT 98   CHECK (target_pct BETWEEN 50 AND 100),   -- >= ini: memenuhi SLA
    warn_pct          NUMERIC(6,3) NOT NULL DEFAULT 97   CHECK (warn_pct BETWEEN 50 AND 100),     -- di bawah ini: tidak memenuhi
    loss_down_pct     NUMERIC(5,2) NOT NULL DEFAULT 10   CHECK (loss_down_pct BETWEEN 1 AND 100), -- loss setinggi ini dihitung gangguan
    latency_target_ms NUMERIC(8,2) NOT NULL DEFAULT 100,
    jitter_target_ms  NUMERIC(8,2) NOT NULL DEFAULT 5,
    loss_target_pct   NUMERIC(5,2) NOT NULL DEFAULT 1,
    exclude_maintenance BOOLEAN    NOT NULL DEFAULT TRUE,
    notes             VARCHAR(500),
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (warn_pct <= target_pct)
);
