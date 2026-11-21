-- ANTAREJA NMS — tahap 3: profil perangkat & sensor

ALTER TABLE devices ADD COLUMN profiles VARCHAR(255);          -- profil terdeteksi, mis. "host-resources,mikrotik"
ALTER TABLE devices ADD COLUMN profiles_at TIMESTAMPTZ;        -- kapan terakhir dideteksi (diulang tiap 24 jam / sysObjectID berubah)

CREATE TABLE device_sensors (
    device_id   BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    key         VARCHAR(96)  NOT NULL,
    class       VARCHAR(24)  NOT NULL,
    grp         VARCHAR(16)  NOT NULL DEFAULT 'sistem',
    label       VARCHAR(255) NOT NULL,
    unit        VARCHAR(16)  NOT NULL DEFAULT '',
    value       DOUBLE PRECISION,
    text        VARCHAR(128),
    status      VARCHAR(4)   NOT NULL DEFAULT 'ok' CHECK (status IN ('ok', 'warn', 'crit')),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, key)
);
CREATE INDEX device_sensors_status_idx ON device_sensors (status) WHERE status <> 'ok';

-- Problem sensor dibedakan per kunci sensor
ALTER TABLE problems ADD COLUMN sensor_key VARCHAR(96);
DROP INDEX problems_open_key;
CREATE UNIQUE INDEX problems_open_key ON problems (device_id, kind, COALESCE(if_index, -1), COALESCE(sensor_key, '')) WHERE resolved_at IS NULL;
