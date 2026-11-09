-- Deliberate one-time cutover: unregistered PHP sessions must authenticate again.
-- The registry contains neither PHP session IDs nor private binding tokens.
CREATE TABLE login_sessions (
    id text PRIMARY KEY CHECK (id ~ '^[A-Za-z0-9_-]{43}$'),
    user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_version integer NOT NULL,
    binding_hash text NOT NULL UNIQUE CHECK (binding_hash ~ '^[0-9a-f]{64}$'),
    client_hint text NOT NULL CHECK (octet_length(client_hint) <= 100),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    last_seen_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    revoked_at timestamptz
);
CREATE INDEX login_sessions_account ON login_sessions(user_id, id);
CREATE INDEX login_sessions_retired ON login_sessions(revoked_at) WHERE revoked_at IS NOT NULL;
ALTER TABLE native_auth_transactions ADD COLUMN login_session_id text;
-- No FK: pruning a revoked login must never modify/relock the native ledger.
COMMENT ON COLUMN native_auth_transactions.login_session_id IS
    'Originating registered login for authenticated native purposes; public identifier only.';

UPDATE users SET session_version = session_version + 1;
DELETE FROM push_subscriptions;

-- All existing version/account-state invalidations use the same account -> login
-- -> subscription -> delivery order, including admin reset, closure and guests.
CREATE FUNCTION invalidate_registered_logins() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.session_version IS DISTINCT FROM OLD.session_version
       OR NEW.account_state IS DISTINCT FROM OLD.account_state THEN
        PERFORM id FROM login_sessions WHERE user_id = NEW.id ORDER BY id FOR UPDATE;
        UPDATE login_sessions SET revoked_at = COALESCE(revoked_at, clock_timestamp())
        WHERE user_id = NEW.id;
        DELETE FROM push_subscriptions WHERE user_id = NEW.id;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER users_invalidate_registered_logins
AFTER UPDATE OF session_version, account_state ON users
FOR EACH ROW EXECUTE FUNCTION invalidate_registered_logins();
