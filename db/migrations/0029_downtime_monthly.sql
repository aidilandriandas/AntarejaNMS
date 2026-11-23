-- ANTAREJA NMS: rekap down bulanan per perangkat (rumus sama dengan laporan SLA: problem "down" dikurangi maintenance)
-- Disimpan supaya riwayat bertahun-tahun tetap ada walau problem lama dibersihkan atau perangkat dihapus/diganti nama.
-- Bulan berjalan diperbarui tiap jam (final = false); bulan yang sudah lewat dihitung ulang sekali lalu dikunci (final = true).

CREATE TABLE downtime_monthly (
    id           BIGSERIAL    PRIMARY KEY,
    month        DATE         NOT NULL CHECK (EXTRACT(DAY FROM month) = 1),
    device_id    BIGINT       REFERENCES devices(id) ON DELETE SET NULL,
    device_name  VARCHAR(128) NOT NULL,   -- salinan saat dihitung (riwayat tetap terbaca bila perangkat dihapus)
    host         VARCHAR(255) NOT NULL,
    site         VARCHAR(64)  NOT NULL,
    monitored_s  DOUBLE PRECISION NOT NULL,
    down_s       DOUBLE PRECISION NOT NULL,
    outages      INTEGER      NOT NULL,
    final        BOOLEAN      NOT NULL DEFAULT FALSE,
    computed_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (month, device_id)
);
CREATE INDEX downtime_monthly_month_idx ON downtime_monthly (month);
