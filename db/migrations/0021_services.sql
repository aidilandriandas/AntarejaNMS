-- ANTAREJA NMS — tahap 7b: SLA per layanan
-- Layanan (mis. "CCTV Pit A") = satu atau lebih komponen. Komponen = sekumpulan perangkat dengan syarat
-- "minimal N hidup" (seri: 1 perangkat min 1; redundan: 2 perangkat min 1). Layanan down bila ada komponen
-- yang tidak memenuhi syarat. Perangkat yang tak terjangkau karena induknya down tetap tercatat down (problem
-- "down" yang ditekan induk), jadi jalur ke perangkat ikut terhitung.

CREATE TABLE services (
    id           BIGSERIAL    PRIMARY KEY,
    name         VARCHAR(120) NOT NULL UNIQUE,
    description  VARCHAR(500),
    site_id      BIGINT       REFERENCES sites(id) ON DELETE SET NULL,
    target_pct   NUMERIC(6,3) CHECK (target_pct IS NULL OR (target_pct >= 50 AND target_pct <= 100)),  -- kosong = target global
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE service_components (
    id           BIGSERIAL    PRIMARY KEY,
    service_id   BIGINT       NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    name         VARCHAR(120) NOT NULL,
    min_up       INTEGER      NOT NULL DEFAULT 1 CHECK (min_up >= 1),
    position     INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX service_components_idx ON service_components (service_id, position);

CREATE TABLE service_component_devices (
    component_id BIGINT NOT NULL REFERENCES service_components(id) ON DELETE CASCADE,
    device_id    BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    PRIMARY KEY (component_id, device_id)
);
CREATE INDEX service_component_devices_dev ON service_component_devices (device_id);
