-- Link ISP berbasis port: router dan port yang mengarah ke ISP. Ketersediaan dari status port (SNMP), trafik dari
-- penghitung port, dan (opsional) latensi/jitter/loss dari ping ke perangkat di ujung ISP. Satu router boleh punya
-- beberapa port ISP. Link lama tanpa port tetap dihitung dari ping perangkatnya seperti sebelumnya.
ALTER TABLE isp_links ADD COLUMN IF NOT EXISTS if_index INTEGER;
ALTER TABLE isp_links ADD COLUMN IF NOT EXISTS ping_device_id BIGINT REFERENCES devices(id) ON DELETE SET NULL;
ALTER TABLE isp_links ADD COLUMN IF NOT EXISTS bandwidth_mbps NUMERIC(10,2) CHECK (bandwidth_mbps IS NULL OR bandwidth_mbps > 0);
ALTER TABLE isp_links DROP CONSTRAINT IF EXISTS isp_links_device_id_key;
CREATE UNIQUE INDEX IF NOT EXISTS isp_links_port_uniq ON isp_links (device_id, COALESCE(if_index, 0));
