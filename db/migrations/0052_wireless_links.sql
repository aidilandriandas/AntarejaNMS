-- Link wireless Ubiquiti (airMAX dan LTU: station yang tersambung ke AP; airFiber: pasangan link PTP).
-- Tiap link juga dicatat sebagai tetangga protokol 'wifi' sehingga dicocokkan ke perangkat terdaftar (MAC atau IP)
-- dan muncul sebagai garis di topologi tanpa diatur manual.
ALTER TABLE neighbors DROP CONSTRAINT IF EXISTS neighbors_protocol_check;
ALTER TABLE neighbors ADD CONSTRAINT neighbors_protocol_check CHECK (protocol IN ('lldp', 'cdp', 'wifi'));

CREATE TABLE IF NOT EXISTS wireless_links (
    device_id         BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE, -- yang melaporkan (AP / sisi airFiber)
    mac               VARCHAR(48)  NOT NULL,                                          -- MAC lawan (station / ujung lain), atau ip:<alamat> bila MAC tidak dilaporkan
    kind              VARCHAR(10)  NOT NULL CHECK (kind IN ('airmax', 'airfiber', 'ltu')),
    name              VARCHAR(255),
    ip                VARCHAR(45),
    remote_device_id  BIGINT       REFERENCES devices(id) ON DELETE SET NULL,
    signal_dbm        REAL,        -- sinyal yang diterima perangkat pelapor dari lawan
    remote_signal_dbm REAL,        -- sinyal yang diterima lawan (airFiber)
    noise_dbm         REAL,
    ccq_pct           REAL,
    quality_pct       REAL,        -- airMAX quality
    capacity_pct      REAL,        -- airMAX capacity
    distance_m        INTEGER,
    tx_mbps           REAL,
    rx_mbps           REAL,
    uptime_s          BIGINT,
    connected         BOOLEAN      NOT NULL DEFAULT true,
    first_seen        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_seen         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, mac)
);
CREATE INDEX IF NOT EXISTS wireless_links_remote_idx ON wireless_links (remote_device_id);

-- Profil sensor baru (airFiber): deteksi ulang semua perangkat pada putaran SNMP berikutnya
UPDATE devices SET profiles_at = NULL WHERE snmp_enabled;
