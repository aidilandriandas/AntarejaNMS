-- ANTAREJA NMS — tahap 6d: peta lokasi (koordinat site & perangkat)

ALTER TABLE sites   ADD COLUMN lat DOUBLE PRECISION CHECK (lat BETWEEN -90 AND 90);
ALTER TABLE sites   ADD COLUMN lng DOUBLE PRECISION CHECK (lng BETWEEN -180 AND 180);
ALTER TABLE devices ADD COLUMN lat DOUBLE PRECISION CHECK (lat BETWEEN -90 AND 90);    -- kosong = ikut posisi site
ALTER TABLE devices ADD COLUMN lng DOUBLE PRECISION CHECK (lng BETWEEN -180 AND 180);

INSERT INTO settings (key, value) VALUES
    -- Sumber gambar peta (diambil langsung oleh browser). Ganti ke server tile internal bila tanpa internet; kosong = tanpa latar peta.
    ('map.tile_url', '"https://tile.openstreetmap.org/{z}/{x}/{y}.png"'),
    ('map.attribution', '"© kontributor OpenStreetMap"')
ON CONFLICT (key) DO NOTHING;
