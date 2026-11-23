-- ANTAREJA NMS: token API baca-saja (integrasi SOC, dashboard lain, skrip)
-- Token asli hanya ditampilkan sekali saat dibuat; yang disimpan hash SHA-256-nya.

CREATE TABLE api_tokens (
    id            BIGSERIAL    PRIMARY KEY,
    name          VARCHAR(128) NOT NULL,
    token_hash    CHAR(64)     NOT NULL UNIQUE,
    prefix        VARCHAR(12)  NOT NULL,          -- awal token untuk dikenali di daftar (bukan rahasia)
    allowed_ips   VARCHAR(500),                   -- kosong = semua; daftar IP/CIDR dipisah koma
    expires_at    TIMESTAMPTZ,
    revoked_at    TIMESTAMPTZ,
    last_used_at  TIMESTAMPTZ,
    last_ip       VARCHAR(45),
    use_count     BIGINT       NOT NULL DEFAULT 0,
    created_by    BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
