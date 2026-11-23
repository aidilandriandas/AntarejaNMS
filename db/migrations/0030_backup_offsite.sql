-- ANTAREJA NMS: salinan backup ke luar server (SFTP atau S3/MinIO), diunggah collector
-- Setelan tujuan di settings 'backup.offsite' (tanpa rahasia); rahasia (password/private key/secret key) terenkripsi
-- di settings 'backup.offsite_secret' (payload Laravel Crypt, kunci ANTAREJA_APP_KEY). Host key SFTP dikunci saat
-- koneksi pertama di 'backup.offsite_hostkey'.

ALTER TABLE backups ADD COLUMN IF NOT EXISTS offsite_status VARCHAR(8) CHECK (offsite_status IN ('pending', 'ok', 'failed'));
ALTER TABLE backups ADD COLUMN IF NOT EXISTS offsite_at TIMESTAMPTZ;
ALTER TABLE backups ADD COLUMN IF NOT EXISTS offsite_error VARCHAR(500);
ALTER TABLE backups ADD COLUMN IF NOT EXISTS offsite_tries SMALLINT NOT NULL DEFAULT 0;

INSERT INTO settings (key, value) VALUES
    ('backup.offsite', '{"type": "off", "keep": 30}')
ON CONFLICT (key) DO NOTHING;
