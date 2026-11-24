-- Scrape exporter Prometheus (node_exporter / windows_exporter) per perangkat. Sensor berkunci "px:".
ALTER TABLE devices ADD COLUMN IF NOT EXISTS exporter_url      VARCHAR(255);   -- NULL = tidak di-scrape
ALTER TABLE devices ADD COLUMN IF NOT EXISTS exporter_services VARCHAR(500);   -- nama layanan dipantau, dipisah koma
ALTER TABLE devices ADD COLUMN IF NOT EXISTS exporter_error    VARCHAR(300);
ALTER TABLE devices ADD COLUMN IF NOT EXISTS exporter_at       TIMESTAMPTZ;
