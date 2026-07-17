ALTER TABLE room_messages
    ADD COLUMN edited_at TIMESTAMPTZ NULL,
    ADD COLUMN edited_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE direct_messages
    ADD COLUMN edited_at TIMESTAMPTZ NULL,
    ADD COLUMN edited_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN deleted_at TIMESTAMPTZ NULL,
    ADD COLUMN deleted_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE direct_messages
    DROP CONSTRAINT IF EXISTS direct_messages_body_check;

ALTER TABLE direct_messages
    ALTER COLUMN body DROP NOT NULL;

ALTER TABLE direct_messages
    ADD CONSTRAINT direct_messages_body_check CHECK (
        (deleted_at IS NULL AND body IS NOT NULL AND char_length(body) BETWEEN 1 AND 4000)
        OR (deleted_at IS NOT NULL AND body IS NULL)
    );

ALTER TABLE direct_message_attachments
    ADD COLUMN deleted_at TIMESTAMPTZ NULL,
    ADD COLUMN deleted_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE direct_message_attachments
    ADD CONSTRAINT direct_message_attachments_deletion_check CHECK (
        (deleted_at IS NULL AND deleted_by IS NULL)
        OR deleted_at IS NOT NULL
    );

CREATE TABLE room_message_revisions (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES room_messages(id) ON DELETE CASCADE,
    action VARCHAR(16) NOT NULL CHECK (action IN ('edit', 'delete')),
    actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    message_type VARCHAR(16) NOT NULL,
    body_before TEXT NOT NULL,
    body_after TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (
        (action = 'edit' AND body_after IS NOT NULL)
        OR (action = 'delete' AND body_after IS NULL)
    )
);

CREATE INDEX room_message_revisions_message
    ON room_message_revisions (message_id, id DESC);

CREATE TABLE direct_message_revisions (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES direct_messages(id) ON DELETE CASCADE,
    action VARCHAR(16) NOT NULL CHECK (action IN ('edit', 'delete')),
    actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    body_before TEXT NOT NULL,
    body_after TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (
        (action = 'edit' AND body_after IS NOT NULL)
        OR (action = 'delete' AND body_after IS NULL)
    )
);

CREATE INDEX direct_message_revisions_message
    ON direct_message_revisions (message_id, id DESC);

CREATE OR REPLACE FUNCTION record_room_message_revision()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
        INSERT INTO room_message_revisions (
            message_id,
            action,
            actor_user_id,
            message_type,
            body_before,
            body_after
        ) VALUES (
            OLD.id,
            'delete',
            NEW.deleted_by,
            OLD.message_type,
            OLD.body,
            NULL
        );
    ELSIF NEW.body IS DISTINCT FROM OLD.body THEN
        INSERT INTO room_message_revisions (
            message_id,
            action,
            actor_user_id,
            message_type,
            body_before,
            body_after
        ) VALUES (
            OLD.id,
            'edit',
            NEW.edited_by,
            OLD.message_type,
            OLD.body,
            NEW.body
        );
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER room_message_revision_trigger
BEFORE UPDATE ON room_messages
FOR EACH ROW
EXECUTE FUNCTION record_room_message_revision();

CREATE OR REPLACE FUNCTION record_direct_message_revision()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
        INSERT INTO direct_message_revisions (
            message_id,
            action,
            actor_user_id,
            body_before,
            body_after
        ) VALUES (
            OLD.id,
            'delete',
            NEW.deleted_by,
            OLD.body,
            NULL
        );
    ELSIF NEW.body IS DISTINCT FROM OLD.body THEN
        INSERT INTO direct_message_revisions (
            message_id,
            action,
            actor_user_id,
            body_before,
            body_after
        ) VALUES (
            OLD.id,
            'edit',
            NEW.edited_by,
            OLD.body,
            NEW.body
        );
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER direct_message_revision_trigger
BEFORE UPDATE ON direct_messages
FOR EACH ROW
EXECUTE FUNCTION record_direct_message_revision();
