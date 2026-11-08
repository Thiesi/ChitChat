CREATE TABLE password_recovery_keys (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    key_hash VARCHAR(64) NOT NULL CHECK (key_hash ~ '^[0-9a-f]{64}$'),
    generation VARCHAR(32) NOT NULL CHECK (generation ~ '^[0-9a-f]{32}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE FUNCTION erase_closed_account_password_recovery()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.account_state = 'closed' AND OLD.account_state IS DISTINCT FROM NEW.account_state THEN
        DELETE FROM password_recovery_keys WHERE user_id = NEW.id;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER erase_closed_account_password_recovery
BEFORE UPDATE OF account_state ON users
FOR EACH ROW EXECUTE FUNCTION erase_closed_account_password_recovery();

COMMENT ON TABLE password_recovery_keys IS
    'One high-entropy password-recovery proof per password account: hash only. Random generation binds pending resets across replacement/deletion; never a second-factor proof.';
