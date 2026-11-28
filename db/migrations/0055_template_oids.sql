-- Sensor OID khusus di templat perangkat: untuk merek yang tidak punya profil bawaan (mis. Cambium, Mimosa, Siklu).
-- Engineer mengisi OID dari MIB vendornya sendiri; collector membacanya sebagai sensor biasa (grafik, ambang, problem).
CREATE TABLE IF NOT EXISTS template_oids (
    id          BIGSERIAL PRIMARY KEY,
    template_id BIGINT       NOT NULL REFERENCES device_templates(id) ON DELETE CASCADE,
    label       VARCHAR(80)  NOT NULL,
    oid         VARCHAR(200) NOT NULL CHECK (oid ~ '^\.?[0-9]+(\.[0-9]+)+$'),
    mode        VARCHAR(4)   NOT NULL DEFAULT 'get' CHECK (mode IN ('get', 'walk')),  -- satu nilai atau satu kolom tabel
    class       VARCHAR(16)  NOT NULL DEFAULT 'other',
    unit        VARCHAR(16),
    scale       NUMERIC(14,6) NOT NULL DEFAULT 1,
    grp         VARCHAR(16)  NOT NULL DEFAULT 'radio',
    enabled     BOOLEAN      NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS template_oids_tpl_idx ON template_oids (template_id);
