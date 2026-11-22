-- ANTAREJA NMS — tahap 6i: halaman status read-only lewat tautan rahasia (untuk manajemen)

CREATE TABLE status_links (
    id            BIGSERIAL    PRIMARY KEY,
    name          VARCHAR(128) NOT NULL,
    token_hash    CHAR(64)     NOT NULL UNIQUE,   -- SHA-256 token; token asli hanya ditampilkan sekali saat dibuat
    site_id       BIGINT       REFERENCES sites(id) ON DELETE CASCADE,   -- kosong = semua site
    show_devices  BOOLEAN      NOT NULL DEFAULT FALSE,   -- tampilkan nama perangkat yang down (tanpa IP)
    expires_at    TIMESTAMPTZ,
    revoked_at    TIMESTAMPTZ,
    last_used_at  TIMESTAMPTZ,
    use_count     BIGINT       NOT NULL DEFAULT 0,
    created_by    BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
