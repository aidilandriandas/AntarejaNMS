-- ANTAREJA NMS — cek kesehatan gambar CCTV

ALTER TABLE cameras ADD COLUMN health VARCHAR(10) CHECK (health IN ('ok', 'dark', 'covered', 'frozen', 'nosignal'));
ALTER TABLE cameras ADD COLUMN health_detail VARCHAR(255);
ALTER TABLE cameras ADD COLUMN health_since TIMESTAMPTZ;   -- sejak kapan status kesehatan ini
ALTER TABLE cameras ADD COLUMN health_at TIMESTAMPTZ;      -- pemeriksaan terakhir
ALTER TABLE cameras ADD COLUMN brightness REAL;            -- rata-rata kecerahan 0–255
ALTER TABLE cameras ADD COLUMN detail_score REAL;          -- simpangan baku kecerahan (rendah = gambar polos/tertutup)
