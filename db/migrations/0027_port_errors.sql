-- ANTAREJA NMS: laju error dan discard port (per menit, masuk + keluar), dihitung collector dari selisih counter IF-MIB.
-- Kosong = belum bisa dihitung (sampel pertama, counter reset, atau perangkat tidak melaporkan counter itu).
ALTER TABLE interfaces ADD COLUMN IF NOT EXISTS err_rate DOUBLE PRECISION;
ALTER TABLE interfaces ADD COLUMN IF NOT EXISTS discard_rate DOUBLE PRECISION;

-- Trigger custom: dua metrik baru
ALTER TABLE triggers DROP CONSTRAINT IF EXISTS triggers_metric_check;
ALTER TABLE triggers ADD CONSTRAINT triggers_metric_check
    CHECK (metric IN ('latency', 'loss', 'sensor', 'port_in', 'port_out', 'port_util', 'port_errors', 'port_discards'));
