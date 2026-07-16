ALTER TABLE room_messages
    DROP CONSTRAINT IF EXISTS room_messages_message_type_check;

ALTER TABLE room_messages
    ADD CONSTRAINT room_messages_message_type_check CHECK (message_type IN (
        'text',
        'emote',
        'system',
        'attachment'
    ));

CREATE TABLE attachments (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL UNIQUE REFERENCES room_messages(id) ON DELETE CASCADE,
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    uploader_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    storage_key CHAR(64) NOT NULL UNIQUE CHECK (storage_key ~ '^[0-9a-f]{64}$'),
    original_name VARCHAR(255) NOT NULL CHECK (char_length(original_name) BETWEEN 1 AND 255),
    mime_type VARCHAR(127) NOT NULL CHECK (char_length(mime_type) BETWEEN 1 AND 127),
    size_bytes BIGINT NOT NULL CHECK (size_bytes BETWEEN 1 AND 104857600),
    sha256 CHAR(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL,
    deleted_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    CHECK (
        (deleted_at IS NULL AND deleted_by IS NULL)
        OR deleted_at IS NOT NULL
    )
);

CREATE INDEX attachments_room_created
    ON attachments (room_id, id DESC);

CREATE INDEX attachments_uploader
    ON attachments (uploader_user_id, id DESC)
    WHERE uploader_user_id IS NOT NULL;
