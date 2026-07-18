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

CREATE FUNCTION enforce_admin_role_mfa_policy()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.role IN ('super_admin', 'admin', 'chat_admin', 'global_moderator')
       AND (SELECT mfa_required_for_admin_roles FROM system_settings WHERE id = 1)
       AND NOT EXISTS (
           SELECT 1
           FROM users u
           WHERE u.id = NEW.user_id
             AND u.account_state = 'active'
             AND u.mfa_enabled_at IS NOT NULL
             AND EXISTS (SELECT 1 FROM webauthn_credentials wc WHERE wc.user_id = u.id)
       )
    THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'mfa_required_for_role';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER user_roles_enforce_admin_mfa
BEFORE INSERT OR UPDATE OF role ON user_roles
FOR EACH ROW
EXECUTE FUNCTION enforce_admin_role_mfa_policy();

CREATE FUNCTION clear_mfa_on_account_tombstone()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM new_user_states new_state
        JOIN old_user_states old_state USING (id)
        WHERE old_state.account_state <> 'closed'
          AND new_state.account_state = 'closed'
    ) THEN
        RETURN NULL;
    END IF;

    DELETE FROM webauthn_credentials
    WHERE user_id IN (
        SELECT new_state.id
        FROM new_user_states new_state
        JOIN old_user_states old_state USING (id)
        WHERE old_state.account_state <> 'closed'
          AND new_state.account_state = 'closed'
    );

    DELETE FROM mfa_recovery_codes
    WHERE user_id IN (
        SELECT new_state.id
        FROM new_user_states new_state
        JOIN old_user_states old_state USING (id)
        WHERE old_state.account_state <> 'closed'
          AND new_state.account_state = 'closed'
    );

    UPDATE users
    SET webauthn_user_handle = NULL,
        mfa_enabled_at = NULL
    WHERE id IN (
        SELECT new_state.id
        FROM new_user_states new_state
        JOIN old_user_states old_state USING (id)
        WHERE old_state.account_state <> 'closed'
          AND new_state.account_state = 'closed'
    )
      AND (webauthn_user_handle IS NOT NULL OR mfa_enabled_at IS NOT NULL);

    RETURN NULL;
END;
$$;

CREATE TRIGGER users_clear_mfa_on_tombstone
AFTER UPDATE ON users
REFERENCING OLD TABLE AS old_user_states NEW TABLE AS new_user_states
FOR EACH STATEMENT
EXECUTE FUNCTION clear_mfa_on_account_tombstone();

COMMENT ON TABLE webauthn_credentials IS
    'WebAuthn public credentials. Private key material never reaches or resides on the ChitChat server.';
COMMENT ON TABLE mfa_recovery_codes IS
    'One-time MFA recovery-code hashes. Plaintext recovery codes are returned once and never stored.';
COMMENT ON FUNCTION enforce_admin_role_mfa_policy() IS
    'Database-level invariant preventing protected role grants to accounts without enabled passkey MFA while enforcement is active.';
COMMENT ON FUNCTION clear_mfa_on_account_tombstone() IS
    'Irreversibly destroys passkeys, recovery codes, and MFA identity material whenever account closure reaches the closed state.';
