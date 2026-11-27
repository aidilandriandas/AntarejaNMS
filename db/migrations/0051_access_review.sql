-- Tinjauan hak akses (ISO/IEC 27001:2022 A.5.18): kapan akun terakhir benar-benar dipakai, termasuk sesi "Ingat saya"
-- yang tidak lewat form login (mis. PC layar NOC). Dipakai untuk menonaktifkan akun yang lama tidak dipakai.
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS disabled_reason VARCHAR(200);
UPDATE users SET last_seen_at = last_login_at WHERE last_seen_at IS NULL;
