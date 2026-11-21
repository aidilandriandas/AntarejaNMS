-- ANTAREJA NMS — tahap 2b: topologi (tetangga LLDP/CDP & sambungan manual)

-- Tetangga yang dilaporkan perangkat lewat LLDP/CDP; diganti tiap putaran SNMP.
-- remote_device_id / remote_if_index diisi bila tetangga cocok dengan perangkat yang dipantau.
CREATE TABLE neighbors (
    id               BIGSERIAL PRIMARY KEY,
    device_id        BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    local_if_index   INTEGER,
    local_port       VARCHAR(255),
    protocol         VARCHAR(4)   NOT NULL CHECK (protocol IN ('lldp', 'cdp')),
    remote_name      VARCHAR(255),
    remote_port      VARCHAR(255),
    remote_chassis   VARCHAR(17),
    remote_ip        VARCHAR(45),
    remote_platform  VARCHAR(255),
    remote_device_id BIGINT REFERENCES devices(id) ON DELETE SET NULL,
    remote_if_index  INTEGER,
    last_seen        TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX neighbors_device_idx ON neighbors (device_id);
CREATE INDEX neighbors_remote_idx ON neighbors (remote_device_id);

-- Sambungan yang dibuat engineer (perangkat tanpa LLDP/CDP: radio, kamera, NVR, perangkat lama).
CREATE TABLE device_links (
    id           BIGSERIAL PRIMARY KEY,
    a_device_id  BIGINT  NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    a_if_index   INTEGER,
    b_device_id  BIGINT  NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    b_if_index   INTEGER,
    note         VARCHAR(255),
    created_by   BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (a_device_id <> b_device_id)
);
