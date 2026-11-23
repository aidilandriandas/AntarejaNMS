-- ANTAREJA NMS: riwayat perubahan status port (up/down) untuk deteksi port naik-turun (flapping)
-- Sumber "poll": perubahan yang terlihat antar putaran SNMP. Sumber "trap": linkDown/linkUp dari perangkat
-- (menangkap putus-sambung cepat di antara dua putaran). Engine memakai trap bila ada, supaya tidak terhitung dua kali.

CREATE TABLE port_changes (
    id         BIGSERIAL    PRIMARY KEY,
    device_id  BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    if_index   INTEGER      NOT NULL,
    up         BOOLEAN      NOT NULL,
    source     VARCHAR(4)   NOT NULL CHECK (source IN ('poll', 'trap')),
    at         TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX port_changes_port_idx ON port_changes (device_id, if_index, at DESC);
CREATE INDEX port_changes_at_idx ON port_changes (at);

INSERT INTO settings (key, value) VALUES
    ('threshold.flap_changes', '4'),   -- perubahan status dalam jendela untuk dianggap naik-turun
    ('threshold.flap_minutes', '15')
ON CONFLICT (key) DO NOTHING;
