-- ANTAREJA NMS — tahap 6e: denah sebagai latar peta (gambar disimpan di database agar ikut backup harian)

CREATE TABLE map_plans (
    id          BIGSERIAL    PRIMARY KEY,
    name        VARCHAR(128) NOT NULL,
    mime        VARCHAR(32)  NOT NULL CHECK (mime IN ('image/png', 'image/jpeg', 'image/webp')),
    width       INTEGER      NOT NULL CHECK (width BETWEEN 1 AND 16000),
    height      INTEGER      NOT NULL CHECK (height BETWEEN 1 AND 16000),
    sha256      CHAR(64)     NOT NULL,
    data        BYTEA        NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Posisi di denah dalam piksel gambar (titik 0,0 = kiri atas). Terpisah dari koordinat peta dunia.
CREATE TABLE map_plan_points (
    plan_id  BIGINT      NOT NULL REFERENCES map_plans(id) ON DELETE CASCADE,
    kind     VARCHAR(6)  NOT NULL CHECK (kind IN ('site', 'device')),
    ref_id   BIGINT      NOT NULL,
    x        REAL        NOT NULL,
    y        REAL        NOT NULL,
    PRIMARY KEY (plan_id, kind, ref_id)
);
