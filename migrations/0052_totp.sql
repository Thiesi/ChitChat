ALTER TABLE users
    ADD COLUMN mfa_policy_generation BIGINT NOT NULL DEFAULT 1 CHECK (mfa_policy_generation > 0);

CREATE TABLE totp_database_key (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    key_id TEXT NOT NULL UNIQUE CHECK (key_id ~ '^db:[a-f0-9]{32}$'),
    key_bytes BYTEA NOT NULL CHECK (octet_length(key_bytes) = 32),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE totp_credentials (
    id TEXT PRIMARY KEY CHECK (id ~ '^[A-Za-z0-9_-]{43}$'),
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    phase TEXT NOT NULL CHECK (phase IN ('pending', 'active')),
    key_id TEXT NOT NULL CHECK (key_id ~ '^([A-Za-z0-9_-]{1,32}|db:[a-f0-9]{32})$'),
    ciphertext BYTEA NOT NULL CHECK (octet_length(ciphertext) = 20),
    iv BYTEA NOT NULL CHECK (octet_length(iv) = 12),
    tag BYTEA NOT NULL CHECK (octet_length(tag) = 16),
    last_accepted_step BIGINT NOT NULL DEFAULT -1 CHECK (last_accepted_step >= -1),
    pending_session_version INTEGER NULL,
    pending_login_id TEXT NULL REFERENCES login_sessions(id) ON DELETE CASCADE,
    expires_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    activated_at TIMESTAMPTZ NULL,
    last_used_at TIMESTAMPTZ NULL,
    CHECK (
        (phase = 'pending' AND pending_session_version IS NOT NULL AND pending_login_id IS NOT NULL
            AND expires_at IS NOT NULL AND activated_at IS NULL AND expires_at > created_at)
        OR (phase = 'active' AND pending_session_version IS NULL AND pending_login_id IS NULL
            AND expires_at IS NULL AND activated_at IS NOT NULL)
    ),
    UNIQUE (user_id, phase)
);
CREATE INDEX totp_credentials_key ON totp_credentials (key_id, user_id, id);

CREATE FUNCTION has_complete_mfa(account_id BIGINT)
RETURNS BOOLEAN LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM users u WHERE u.id = account_id
          AND u.account_state IN ('active', 'closure_pending')
          AND u.mfa_enabled_at IS NOT NULL
          AND (
              EXISTS (SELECT 1 FROM webauthn_credentials wc WHERE wc.user_id = u.id)
              OR EXISTS (SELECT 1 FROM totp_credentials tc WHERE tc.user_id = u.id AND tc.phase = 'active')
          )
    );
$$;

CREATE OR REPLACE FUNCTION enforce_admin_role_mfa_policy()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.role IN ('super_admin', 'admin', 'chat_admin', 'global_moderator')
       AND (SELECT mfa_required_for_admin_roles FROM system_settings WHERE id = 1)
       AND NOT (
           EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND account_state = 'active')
           AND has_complete_mfa(NEW.user_id)
       )
    THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'mfa_required_for_role';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION bump_mfa_policy_generation()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    affected_user BIGINT;
    material BOOLEAN;
BEGIN
    affected_user := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
    IF TG_TABLE_NAME = 'webauthn_credentials' THEN
        material := TRUE;
    ELSIF TG_OP = 'DELETE' THEN
        material := OLD.phase = 'active';
    ELSIF TG_OP = 'INSERT' THEN
        material := NEW.phase = 'active';
    ELSE
        material := OLD.phase IS DISTINCT FROM NEW.phase;
    END IF;
    -- Tombstone/cascade triggers already invalidate every login. Updating the
    -- same user from a nested BEFORE-tombstone delete would update its tuple twice.
    IF material AND pg_trigger_depth() = 1 THEN
        UPDATE users SET mfa_policy_generation = mfa_policy_generation + 1
        WHERE id = affected_user;
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER webauthn_policy_generation AFTER INSERT OR DELETE ON webauthn_credentials
    FOR EACH ROW EXECUTE FUNCTION bump_mfa_policy_generation();
CREATE TRIGGER totp_policy_generation AFTER INSERT OR DELETE OR UPDATE OF phase ON totp_credentials
    FOR EACH ROW EXECUTE FUNCTION bump_mfa_policy_generation();

CREATE OR REPLACE FUNCTION clear_mfa_on_account_tombstone()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.account_state <> 'closed' AND NEW.account_state = 'closed' THEN
        DELETE FROM totp_credentials WHERE user_id = OLD.id;
        DELETE FROM webauthn_credentials WHERE user_id = OLD.id;
        DELETE FROM mfa_recovery_codes WHERE user_id = OLD.id;
        NEW.webauthn_user_handle := NULL;
        NEW.mfa_enabled_at := NULL;
        NEW.mfa_policy_generation := OLD.mfa_policy_generation + 1;
    END IF;
    RETURN NEW;
END;
$$;
COMMENT ON TABLE totp_credentials IS
    'AES-256-GCM encrypted authenticator seeds, with context-bound pending enrollment and a monotonic accepted-step replay fence.';
COMMENT ON TABLE totp_database_key IS
    'Optional operator-selected DB master key: a database backup contains both this key and encrypted seeds. Server keys take precedence for new writes.';
