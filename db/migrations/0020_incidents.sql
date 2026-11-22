-- ANTAREJA NMS — tahap 7a: incident management (insiden mengelompokkan problem)

CREATE TABLE incidents (
    id           BIGSERIAL    PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    severity     VARCHAR(10)  NOT NULL CHECK (severity IN ('info', 'warning', 'average', 'high', 'disaster')),
    status       VARCHAR(10)  NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'handling', 'recovered', 'closed')),
    site_id      BIGINT       REFERENCES sites(id) ON DELETE SET NULL,
    owner_id     BIGINT       REFERENCES users(id) ON DELETE SET NULL,     -- penanggung jawab
    auto         BOOLEAN      NOT NULL DEFAULT FALSE,                       -- dibuat otomatis oleh collector
    cause        TEXT,                                                      -- penyebab (RCA)
    action_taken TEXT,                                                      -- tindakan perbaikan
    opened_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    handled_at   TIMESTAMPTZ,                                               -- pertama kali ditangani
    recovered_at TIMESTAMPTZ,                                               -- semua problem pulih
    closed_at    TIMESTAMPTZ,
    created_by   BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX incidents_status_idx ON incidents (status, opened_at DESC);
CREATE INDEX incidents_opened_idx ON incidents (opened_at DESC);

-- Satu problem paling banyak di satu insiden
CREATE TABLE incident_problems (
    incident_id  BIGINT      NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    problem_id   BIGINT      NOT NULL UNIQUE REFERENCES problems(id) ON DELETE CASCADE,
    added_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (incident_id, problem_id)
);

-- Kronologi: catatan tim & perubahan otomatis
CREATE TABLE incident_notes (
    id           BIGSERIAL    PRIMARY KEY,
    incident_id  BIGINT       NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    user_id      BIGINT       REFERENCES users(id) ON DELETE SET NULL,      -- kosong = sistem
    kind         VARCHAR(10)  NOT NULL CHECK (kind IN ('note', 'status', 'problem', 'owner', 'system')),
    body         TEXT         NOT NULL,
    at           TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX incident_notes_idx ON incident_notes (incident_id, at);

INSERT INTO settings (key, value) VALUES
    ('incident.auto', 'true'),             -- buat insiden otomatis
    ('incident.group_min', '3'),           -- ≥ N problem High/Disaster di satu site dalam jendela → insiden
    ('incident.window_min', '10'),         -- jendela pengelompokan (menit)
    ('incident.attach_min', '60')          -- problem baru di site yang sama digabung ke insiden terbuka yang dibuka ≤ N menit lalu
ON CONFLICT (key) DO NOTHING;
