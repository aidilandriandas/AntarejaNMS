-- ANTAREJA NMS: verifikasi dua langkah (TOTP, RFC 6238) dan login lewat LDAP/Active Directory

ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret_enc TEXT;          -- rahasia TOTP terenkripsi (kunci aplikasi)
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_step BIGINT;         -- langkah waktu terakhir yang dipakai (kode tak bisa dipakai ulang)
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_recovery JSONB;           -- hash kode cadangan sekali pakai
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_source VARCHAR(8) NOT NULL DEFAULT 'local' CHECK (auth_source IN ('local', 'ldap'));
ALTER TABLE users ADD COLUMN IF NOT EXISTS ldap_dn VARCHAR(500);

INSERT INTO settings (key, value) VALUES
    ('auth.require_2fa', '"none"'),     -- none | admin | all
    ('auth.ldap', '{"enabled": false}')
ON CONFLICT (key) DO NOTHING;
