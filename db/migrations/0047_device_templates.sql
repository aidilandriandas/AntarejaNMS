-- Templat perangkat (ala Zabbix): ambang, makro, exporter, cek layanan, dan trigger yang dipasang ke banyak
-- perangkat sekaligus. Ambang perangkat sendiri menang atas ambang templat. Cek layanan dari templat dibuat per
-- perangkat (service_checks.template_check_id) dan diperbarui saat templat diubah.
CREATE TABLE IF NOT EXISTS device_templates (
    id                BIGSERIAL PRIMARY KEY,
    name              VARCHAR(80)  NOT NULL UNIQUE,
    description       VARCHAR(500),
    thresholds        JSONB        NOT NULL DEFAULT '{}'::jsonb,
    macros            JSONB        NOT NULL DEFAULT '{}'::jsonb,   -- nilai bawaan {$NAMA}
    exporter_url      VARCHAR(255),                                 -- boleh memakai {HOST} dan makro
    exporter_services VARCHAR(500),
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS template_checks (
    id             BIGSERIAL PRIMARY KEY,
    template_id    BIGINT       NOT NULL REFERENCES device_templates(id) ON DELETE CASCADE,
    name           VARCHAR(128) NOT NULL,
    type           VARCHAR(8)   NOT NULL CHECK (type IN ('http', 'tcp', 'dns')),
    url            VARCHAR(500),
    port           VARCHAR(64),     -- angka atau makro, mis. {$PORT_WEB}
    query          VARCHAR(255),
    expect_status  VARCHAR(32),
    expect_text    VARCHAR(200),
    expect_answer  VARCHAR(255),
    verify_tls     BOOLEAN      NOT NULL DEFAULT true,
    interval_s     INTEGER      NOT NULL DEFAULT 60,
    timeout_s      INTEGER      NOT NULL DEFAULT 10,
    slow_ms        INTEGER,
    cert_warn_days INTEGER,
    severity       VARCHAR(10)  NOT NULL DEFAULT 'high',
    enabled        BOOLEAN      NOT NULL DEFAULT true,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS template_id BIGINT REFERENCES device_templates(id) ON DELETE SET NULL;
ALTER TABLE devices ADD COLUMN IF NOT EXISTS macros JSONB NOT NULL DEFAULT '{}'::jsonb;   -- menimpa makro templat
ALTER TABLE devices ADD COLUMN IF NOT EXISTS exporter_from_template BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE service_checks ADD COLUMN IF NOT EXISTS template_check_id BIGINT REFERENCES template_checks(id) ON DELETE CASCADE;
CREATE UNIQUE INDEX IF NOT EXISTS service_checks_tpl_idx ON service_checks (device_id, template_check_id) WHERE template_check_id IS NOT NULL;
ALTER TABLE triggers ADD COLUMN IF NOT EXISTS template_id BIGINT REFERENCES device_templates(id) ON DELETE CASCADE;
ALTER TABLE triggers DROP CONSTRAINT IF EXISTS triggers_scope_check;
ALTER TABLE triggers ADD CONSTRAINT triggers_scope_check CHECK (scope IN ('all', 'site', 'category', 'devices', 'template'));
