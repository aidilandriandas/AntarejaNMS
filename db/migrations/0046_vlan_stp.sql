-- VLAN per switch, VLAN bawaan (PVID) dan status STP per port, ringkasan STP per switch.
CREATE TABLE IF NOT EXISTS device_vlans (
    device_id  BIGINT      NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    vlan_id    INTEGER     NOT NULL CHECK (vlan_id BETWEEN 1 AND 4094),
    name       VARCHAR(64),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, vlan_id)
);
CREATE INDEX IF NOT EXISTS device_vlans_vlan_idx ON device_vlans (vlan_id);
ALTER TABLE interfaces ADD COLUMN IF NOT EXISTS pvid      INTEGER;
ALTER TABLE interfaces ADD COLUMN IF NOT EXISTS stp_state SMALLINT;  -- 1 disabled, 2 blocking, 3 listening, 4 learning, 5 forwarding, 6 broken
ALTER TABLE devices ADD COLUMN IF NOT EXISTS stp_root          VARCHAR(32);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS stp_root_port     INTEGER;   -- ifIndex; 0 = switch ini root bridge
ALTER TABLE devices ADD COLUMN IF NOT EXISTS stp_top_changes   BIGINT;
ALTER TABLE devices ADD COLUMN IF NOT EXISTS stp_last_change_at TIMESTAMPTZ;
