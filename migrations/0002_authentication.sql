CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(32) NOT NULL,
    username_canonical VARCHAR(32) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    session_version BIGINT NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_login_at TIMESTAMPTZ NULL
);

CREATE TABLE user_roles (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(32) NOT NULL CHECK (role IN ('super_admin', 'admin', 'chat_admin', 'global_moderator')),
    granted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, role)
);

CREATE TABLE user_bans (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_by BIGINT NOT NULL REFERENCES users(id),
    reason VARCHAR(500) NOT NULL DEFAULT '',
    starts_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NULL,
    revoked_at TIMESTAMPTZ NULL,
    revoked_by BIGINT NULL REFERENCES users(id)
);

CREATE INDEX user_bans_active_lookup
    ON user_bans (user_id, starts_at, expires_at)
    WHERE revoked_at IS NULL;

CREATE TABLE login_attempts (
    id BIGSERIAL PRIMARY KEY,
    username_canonical VARCHAR(32) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    successful BOOLEAN NOT NULL,
    reason VARCHAR(32) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX login_attempts_throttle_username
    ON login_attempts (username_canonical, created_at DESC)
    WHERE successful = FALSE;

CREATE INDEX login_attempts_throttle_ip
    ON login_attempts (ip_address, created_at DESC)
    WHERE successful = FALSE;

CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(80) NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id VARCHAR(120) NULL,
    metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address VARCHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX audit_log_created_at ON audit_log (created_at DESC);
CREATE INDEX audit_log_actor ON audit_log (actor_user_id, created_at DESC);
