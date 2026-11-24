-- Versi perangkat lunak, model, dan nomor seri dari ENTITY-MIB / sysDescr (dibaca tiap 24 jam, hanya baca).
ALTER TABLE devices ADD COLUMN IF NOT EXISTS os_version   VARCHAR(128);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS hw_model     VARCHAR(128);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS serial_no    VARCHAR(128);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS inventory_at TIMESTAMPTZ;
