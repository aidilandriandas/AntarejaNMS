-- ANTAREJA NMS — tabel aplikasi web (tahap 1b)

CREATE TABLE users (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(128) NOT NULL,
    username        VARCHAR(64)  NOT NULL UNIQUE,
    email           VARCHAR(255),
    password        VARCHAR(255) NOT NULL,
    role            VARCHAR(16)  NOT NULL DEFAULT 'viewer' CHECK (role IN ('admin', 'engineer', 'viewer')),
    active          BOOLEAN      NOT NULL DEFAULT TRUE,
    remember_token  VARCHAR(100),
    last_login_at   TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TABLE sessions (
    id             VARCHAR(255) PRIMARY KEY,
    user_id        BIGINT REFERENCES users(id) ON DELETE CASCADE,
    ip_address     VARCHAR(45),
    user_agent     TEXT,
    payload        TEXT    NOT NULL,
    last_activity  INTEGER NOT NULL
);
CREATE INDEX sessions_user_idx ON sessions (user_id);
CREATE INDEX sessions_activity_idx ON sessions (last_activity);

CREATE TABLE cache (
    key         VARCHAR(255) PRIMARY KEY,
    value       TEXT    NOT NULL,
    expiration  INTEGER NOT NULL
);
CREATE TABLE cache_locks (
    key         VARCHAR(255) PRIMARY KEY,
    owner       VARCHAR(255) NOT NULL,
    expiration  INTEGER NOT NULL
);

CREATE TABLE audit_logs (
    id            BIGSERIAL PRIMARY KEY,
    user_id       BIGINT REFERENCES users(id) ON DELETE SET NULL,
    action        VARCHAR(32)  NOT NULL,          -- created, updated, deleted, login, login_failed, ...
    subject_type  VARCHAR(32),
    subject_id    BIGINT,
    description   VARCHAR(500) NOT NULL,
    changes       JSONB,                          -- nilai lama/baru; kredensial tidak pernah dicatat
    ip_address    VARCHAR(45),
    at            TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_at_idx ON audit_logs (at DESC);

ALTER TABLE devices ADD COLUMN notes TEXT;
ALTER TABLE sites ADD COLUMN description TEXT;
