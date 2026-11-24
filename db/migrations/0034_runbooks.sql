-- Runbook: langkah penanganan yang tampil di problem, halaman perangkat, dan notifikasi.
-- Kolom cakupan yang diisi harus cocok semua; yang kosong berarti semua. Bila beberapa cocok, yang paling
-- spesifik dipakai: perangkat (8) > site (4) > kategori (2) > jenis problem (1).
CREATE TABLE IF NOT EXISTS runbooks (
    id            BIGSERIAL PRIMARY KEY,
    title         VARCHAR(160) NOT NULL,
    body          TEXT         NOT NULL,
    problem_kind  VARCHAR(16),
    category      VARCHAR(32),
    site_id       BIGINT REFERENCES sites(id) ON DELETE CASCADE,    -- site dihapus: runbook ikut, supaya tidak tiba-tiba berlaku untuk semua
    device_id     BIGINT REFERENCES devices(id) ON DELETE CASCADE,
    enabled       BOOLEAN      NOT NULL DEFAULT true,
    updated_by    BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
