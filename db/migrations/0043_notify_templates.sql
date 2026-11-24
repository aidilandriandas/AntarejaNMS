-- Templat isi pesan per kanal (kosong = format bawaan). Penanda {judul}, {site}, dll. diganti saat kirim.
ALTER TABLE notify_channels ADD COLUMN IF NOT EXISTS template_open    TEXT;
ALTER TABLE notify_channels ADD COLUMN IF NOT EXISTS template_resolve TEXT;
