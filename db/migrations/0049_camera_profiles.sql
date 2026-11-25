-- Profil login kamera: user & password RTSP (plus port dan path bawaan) dipakai banyak kamera sekaligus.
-- Kamera yang memakai profil mengambil user & password dari profil, jadi ganti password cukup di satu tempat.
CREATE TABLE IF NOT EXISTS camera_profiles (
    id           BIGSERIAL PRIMARY KEY,
    name         VARCHAR(80)  NOT NULL UNIQUE,
    username     VARCHAR(64),
    password_enc TEXT,                                 -- terenkripsi, tidak pernah dikirim ke browser
    rtsp_port    INTEGER      NOT NULL DEFAULT 554 CHECK (rtsp_port BETWEEN 1 AND 65535),
    path_main    VARCHAR(255) NOT NULL DEFAULT '/Streaming/Channels/{ch}01',
    path_sub     VARCHAR(255) NOT NULL DEFAULT '/Streaming/Channels/{ch}02',
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
ALTER TABLE cameras ADD COLUMN IF NOT EXISTS profile_id BIGINT REFERENCES camera_profiles(id) ON DELETE RESTRICT;
CREATE INDEX IF NOT EXISTS cameras_profile_idx ON cameras (profile_id);
