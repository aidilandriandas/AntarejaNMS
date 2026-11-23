-- ANTAREJA NMS: jadwal piket
-- Satu slot = satu engineer piket dari starts_at sampai ends_at. Slot boleh tumpang tindih (dua orang piket bersamaan).
-- Kanal notifikasi dengan to_oncall = TRUE dikirim ke kontak orang yang sedang piket; tujuan tetap kanal dipakai bila tidak ada yang piket.

CREATE TABLE oncall_slots (
    id          BIGSERIAL    PRIMARY KEY,
    user_id     BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    starts_at   TIMESTAMPTZ  NOT NULL,
    ends_at     TIMESTAMPTZ  NOT NULL,
    note        VARCHAR(160),
    created_by  BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (ends_at > starts_at),
    CHECK (ends_at - starts_at <= interval '7 days')
);
CREATE INDEX oncall_slots_time ON oncall_slots (starts_at, ends_at);

ALTER TABLE users ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(32);
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(32);
ALTER TABLE notify_channels ADD COLUMN IF NOT EXISTS to_oncall BOOLEAN NOT NULL DEFAULT FALSE;
