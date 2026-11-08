CREATE TABLE policy_documents (
    document VARCHAR(16) PRIMARY KEY CHECK (document IN ('rules', 'privacy', 'legal_notice')),
    source VARCHAR(8) NOT NULL DEFAULT 'none' CHECK (source IN ('none', 'hosted', 'external')),
    markdown TEXT NULL,
    url VARCHAR(2048) NULL,
    version INTEGER NOT NULL DEFAULT 0 CHECK (version >= 0),
    change_summary VARCHAR(200) NULL,
    updated_at TIMESTAMPTZ NULL,
    CHECK ((source = 'none' AND markdown IS NULL AND url IS NULL)
        OR (source = 'hosted' AND markdown IS NOT NULL AND url IS NULL AND document <> 'legal_notice')
        OR (source = 'external' AND markdown IS NULL AND url IS NOT NULL)),
    CHECK (source = 'none' OR document = 'legal_notice' OR version > 0),
    CHECK (markdown IS NULL OR octet_length(markdown) <= 65536)
);
INSERT INTO policy_documents (document) VALUES ('rules'), ('privacy'), ('legal_notice');

CREATE TABLE rules_acceptances (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    version INTEGER NOT NULL CHECK (version > 0),
    accepted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, version)
);

-- These records are private account data, like the notification timeline.
CREATE FUNCTION clear_rules_acceptances_on_tombstone() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.account_state <> 'closed' AND NEW.account_state = 'closed' THEN
        DELETE FROM rules_acceptances WHERE user_id = OLD.id;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER users_clear_rules_acceptances_on_tombstone
    BEFORE UPDATE OF account_state ON users FOR EACH ROW
    EXECUTE FUNCTION clear_rules_acceptances_on_tombstone();

ALTER TABLE account_notifications DROP CONSTRAINT account_notifications_kind_check;
ALTER TABLE account_notifications ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
    'revision_review', 'moderator_message_deleted', 'admin_password_reset',
    'system_policy_changed', 'mentioned', 'pinged', 'room_deleted', 'room_restored',
    'avatar_removed', 'muted', 'guest_welcome', 'privacy_policy_updated'
));
