-- ANTAREJA NMS — tahap 6h: susunan Ringkasan per pengguna
-- [{"k": "problems", "w": "half"}, ...]; kosong = susunan bawaan
ALTER TABLE users ADD COLUMN dashboard JSONB;
