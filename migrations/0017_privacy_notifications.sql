CREATE TABLE account_notifications (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    source_audit_id BIGINT NULL REFERENCES audit_log(id) ON DELETE SET NULL,
    kind VARCHAR(48) NOT NULL CHECK (kind IN (
        'revision_review',
        'moderator_message_deleted',
        'admin_password_reset',
        'system_policy_changed'
    )),
    context_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    read_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (jsonb_typeof(context_json) = 'object')
);

CREATE INDEX account_notifications_user_timeline
    ON account_notifications (user_id, id DESC);

CREATE INDEX account_notifications_user_unread
    ON account_notifications (user_id, id DESC)
    WHERE read_at IS NULL;

CREATE UNIQUE INDEX account_notifications_source_once
    ON account_notifications (user_id, source_audit_id)
    WHERE source_audit_id IS NOT NULL;

CREATE FUNCTION create_privacy_notifications_from_audit()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    old_settings JSONB;
    new_settings JSONB;
BEGIN
    IF NEW.action = 'admin.message_revisions_reviewed' THEN
        IF NEW.metadata_json->>'message_kind' = 'room' THEN
            INSERT INTO account_notifications (
                user_id,
                source_audit_id,
                kind,
                context_json,
                created_at
            )
            SELECT account.id,
                   NEW.id,
                   'revision_review',
                   jsonb_strip_nulls(jsonb_build_object(
                       'message_kind', 'room',
                       'message_id', NULLIF(NEW.metadata_json->>'message_id', '')::BIGINT,
                       'room_id', NULLIF(NEW.metadata_json->>'room_id', '')::BIGINT,
                       'room_name', NEW.metadata_json->>'room_name'
                   )),
                   NEW.created_at
            FROM users account
            WHERE account.id = NULLIF(NEW.metadata_json->>'author_user_id', '')::BIGINT
              AND account.account_state <> 'closed'
            ON CONFLICT DO NOTHING;
        ELSIF NEW.metadata_json->>'message_kind' = 'direct' THEN
            INSERT INTO account_notifications (
                user_id,
                source_audit_id,
                kind,
                context_json,
                created_at
            )
            SELECT DISTINCT account.id,
                            NEW.id,
                            'revision_review',
                            jsonb_strip_nulls(jsonb_build_object(
                                'message_kind', 'direct',
                                'message_id', NULLIF(NEW.metadata_json->>'message_id', '')::BIGINT
                            )),
                            NEW.created_at
            FROM (
                VALUES
                    (NULLIF(NEW.metadata_json->>'sender_user_id', '')::BIGINT),
                    (NULLIF(NEW.metadata_json->>'recipient_user_id', '')::BIGINT)
            ) AS recipient(user_id)
            JOIN users account ON account.id = recipient.user_id
            WHERE recipient.user_id IS NOT NULL
              AND account.account_state <> 'closed'
            ON CONFLICT DO NOTHING;
        END IF;
    ELSIF NEW.action = 'room.message_deleted' THEN
        INSERT INTO account_notifications (
            user_id,
            source_audit_id,
            kind,
            context_json,
            created_at
        )
        SELECT account.id,
               NEW.id,
               'moderator_message_deleted',
               jsonb_build_object(
                   'message_id', message.id,
                   'room_id', room.id,
                   'room_name', room.name
               ),
               NEW.created_at
        FROM room_messages message
        JOIN rooms room ON room.id = message.room_id
        JOIN users account ON account.id = message.sender_id
        WHERE message.id = NULLIF(NEW.subject_id, '')::BIGINT
          AND account.account_state <> 'closed'
        ON CONFLICT DO NOTHING;
    ELSIF NEW.action = 'auth.password_reset_by_admin' THEN
        INSERT INTO account_notifications (
            user_id,
            source_audit_id,
            kind,
            context_json,
            created_at
        )
        SELECT account.id,
               NEW.id,
               'admin_password_reset',
               '{}'::jsonb,
               NEW.created_at
        FROM users account
        WHERE account.id = NULLIF(NEW.subject_id, '')::BIGINT
          AND account.account_state <> 'closed'
        ON CONFLICT DO NOTHING;
    ELSIF NEW.action = 'system.settings_updated' THEN
        old_settings := COALESCE(NEW.metadata_json->'old', '{}'::jsonb) - 'updated_at';
        new_settings := COALESCE(NEW.metadata_json->'new', '{}'::jsonb) - 'updated_at';

        IF old_settings IS DISTINCT FROM new_settings THEN
            INSERT INTO account_notifications (
                user_id,
                source_audit_id,
                kind,
                context_json,
                created_at
            )
            SELECT account.id,
                   NEW.id,
                   'system_policy_changed',
                   jsonb_build_object('old', old_settings, 'new', new_settings),
                   NEW.created_at
            FROM users account
            WHERE account.account_state = 'active'
            ON CONFLICT DO NOTHING;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER audit_log_create_privacy_notifications
AFTER INSERT ON audit_log
FOR EACH ROW
EXECUTE FUNCTION create_privacy_notifications_from_audit();

CREATE FUNCTION clear_privacy_notifications_on_account_tombstone()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.account_state <> 'closed' AND NEW.account_state = 'closed' THEN
        DELETE FROM account_notifications WHERE user_id = OLD.id;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER users_clear_privacy_notifications_on_tombstone
BEFORE UPDATE OF account_state ON users
FOR EACH ROW
EXECUTE FUNCTION clear_privacy_notifications_on_account_tombstone();

COMMENT ON TABLE account_notifications IS
    'Durable participant-facing notices derived atomically from audited privacy and security events. Context excludes message bodies, administrator reasons, IP addresses, credentials, and recovery material.';
COMMENT ON FUNCTION create_privacy_notifications_from_audit() IS
    'Creates bounded privacy notifications from selected append-only audit actions in the same transaction as the audited operation.';
COMMENT ON FUNCTION clear_privacy_notifications_on_account_tombstone() IS
    'Removes private account notification history when account closure becomes irreversible.';
