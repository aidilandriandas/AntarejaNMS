-- Akun yang dibatasi ke site tertentu. Tanpa baris = melihat semua site (perilaku lama).
CREATE TABLE IF NOT EXISTS user_sites (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, site_id)
);
