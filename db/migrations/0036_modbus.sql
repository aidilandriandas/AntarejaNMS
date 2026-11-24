-- Modbus TCP (hanya baca, fungsi 03/04) untuk solar controller, baterai, genset, dan sensor daya di menara/site.
-- Hasil baca disimpan sebagai sensor perangkat (kunci "mb:<id titik>") sehingga halaman Sensor, grafik, problem,
-- dan trigger berlaku seperti sensor SNMP.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS modbus_port INTEGER CHECK (modbus_port BETWEEN 1 AND 65535);  -- NULL = Modbus mati
ALTER TABLE devices ADD COLUMN IF NOT EXISTS modbus_unit SMALLINT NOT NULL DEFAULT 1 CHECK (modbus_unit BETWEEN 0 AND 255);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS modbus_error VARCHAR(300);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS modbus_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS modbus_points (
    id          BIGSERIAL PRIMARY KEY,
    device_id   BIGINT       NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    label       VARCHAR(80)  NOT NULL,
    class       VARCHAR(16)  NOT NULL CHECK (class IN ('voltage', 'current', 'power', 'battery', 'fuel', 'temperature', 'other')),
    unit        VARCHAR(12)  NOT NULL DEFAULT '',
    fn          SMALLINT     NOT NULL DEFAULT 3 CHECK (fn IN (3, 4)),          -- 3 holding, 4 input register
    address     INTEGER      NOT NULL CHECK (address BETWEEN 0 AND 65535),     -- alamat register (mulai 0)
    dtype       VARCHAR(4)   NOT NULL DEFAULT 'u16' CHECK (dtype IN ('u16', 's16', 'u32', 's32', 'f32')),
    word_swap   BOOLEAN      NOT NULL DEFAULT false,                           -- 32-bit: word rendah dulu
    scale       DOUBLE PRECISION NOT NULL DEFAULT 1,
    offset_value DOUBLE PRECISION NOT NULL DEFAULT 0,
    warn_low    DOUBLE PRECISION,
    warn_high   DOUBLE PRECISION,
    crit_low    DOUBLE PRECISION,
    crit_high   DOUBLE PRECISION,
    position    INTEGER      NOT NULL DEFAULT 0,
    enabled     BOOLEAN      NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS modbus_points_device_idx ON modbus_points (device_id);
