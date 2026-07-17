ALTER TABLE users
    ADD COLUMN webauthn_user_handle BYTEA NULL,
    ADD COLUMN mfa_enabled_at TIMESTAMPTZ NULL;

CREATE UNIQUE INDEX users_webauthn_user_handle
    ON users (webauthn_user_handle)
    WHERE webauthn_user_handle IS NOT NULL;

ALTER TABLE system_settings
    ADD COLUMN mfa_required_for_admin_roles BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE webauthn_credentials (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id BYTEA NOT NULL UNIQUE,
    public_key_cose BYTEA NOT NULL,
    algorithm INTEGER NOT NULL CHECK (algorithm IN (-7, -257)),
    sign_count BIGINT NOT NULL DEFAULT 0 CHECK (sign_count >= 0),
    transports TEXT[] NOT NULL DEFAULT ARRAY[]::TEXT[],
    backup_eligible BOOLEAN NOT NULL DEFAULT FALSE,
    backup_state BOOLEAN NOT NULL DEFAULT FALSE,
    label VARCHAR(80) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (NOT backup_state OR backup_eligible),
    CHECK (char_length(btrim(label)) BETWEEN 1 AND 80)
);

CREATE INDEX webauthn_credentials_user
    ON webauthn_credentials (user_id, id);

CREATE TABLE mfa_recovery_codes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash BYTEA NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    used_at TIMESTAMPTZ NULL,
    UNIQUE (user_id, code_hash)
);

CREATE INDEX mfa_recovery_codes_available
    ON mfa_recovery_codes (user_id, id)
    WHERE used_at IS NULL;

COMMENT ON TABLE webauthn_credentials IS
    'WebAuthn public credentials. Private key material never reaches or resides on the ChitChat server.';
COMMENT ON TABLE mfa_recovery_codes IS
    'One-time MFA recovery-code hashes. Plaintext recovery codes are returned once and never stored.';