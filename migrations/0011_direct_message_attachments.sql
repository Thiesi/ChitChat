CREATE TABLE direct_message_attachments (
    id BIGSERIAL PRIMARY KEY,
    direct_message_id BIGINT NOT NULL UNIQUE REFERENCES direct_messages(id) ON DELETE CASCADE,
    uploader_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    storage_key CHAR(64) NOT NULL UNIQUE CHECK (storage_key ~ '^[0-9a-f]{64}$'),
    original_name VARCHAR(255) NOT NULL CHECK (char_length(original_name) BETWEEN 1 AND 255),
    mime_type VARCHAR(127) NOT NULL CHECK (char_length(mime_type) BETWEEN 1 AND 127),
    size_bytes BIGINT NOT NULL CHECK (size_bytes BETWEEN 1 AND 104857600),
    sha256 CHAR(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX direct_message_attachments_uploader
    ON direct_message_attachments (uploader_user_id, id DESC)
    WHERE uploader_user_id IS NOT NULL;
