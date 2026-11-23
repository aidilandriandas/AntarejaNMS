-- ANTAREJA NMS: penerima SNMP trap (v1 dan v2c, UDP)
-- Trap dicatat apa adanya; yang dikenal (link, restart, UPS, autentikasi) diberi nama, severity, dan ringkasan.
-- Trap tidak membuat problem sendiri: link dan UPS memicu pembacaan SNMP segera sehingga problem port/sensor
-- dibuat oleh engine seperti biasa (satu sumber kebenaran).

CREATE TABLE traps (
    id           BIGSERIAL    PRIMARY KEY,
    received_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    device_id    BIGINT       REFERENCES devices(id) ON DELETE SET NULL,   -- kosong = pengirim belum terdaftar
    source       INET         NOT NULL,
    version      VARCHAR(3)   NOT NULL,
    trap_oid     VARCHAR(255) NOT NULL,
    name         VARCHAR(64),                    -- linkDown, coldStart, upsTrapOnBattery, ...
    severity     VARCHAR(10)  NOT NULL CHECK (severity IN ('info', 'warning', 'average', 'high')),
    if_index     INTEGER,
    summary      VARCHAR(500) NOT NULL,
    varbinds     JSONB        NOT NULL DEFAULT '[]'
);
CREATE INDEX traps_time_idx   ON traps (received_at DESC);
CREATE INDEX traps_device_idx ON traps (device_id, received_at DESC);

INSERT INTO settings (key, value) VALUES ('trap.keep_days', '30') ON CONFLICT (key) DO NOTHING;
