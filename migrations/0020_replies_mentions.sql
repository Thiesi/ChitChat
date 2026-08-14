ALTER TABLE room_messages
    ADD COLUMN reply_to_message_kind VARCHAR(16) NULL CHECK (reply_to_message_kind IN ('room', 'direct')),
    ADD COLUMN reply_to_message_id BIGINT NULL;

ALTER TABLE room_messages
    ADD CONSTRAINT room_messages_reply_to_paired_check CHECK (
        (reply_to_message_kind IS NULL) = (reply_to_message_id IS NULL)
    );

ALTER TABLE direct_messages
    ADD COLUMN reply_to_message_kind VARCHAR(16) NULL CHECK (reply_to_message_kind IN ('room', 'direct')),
    ADD COLUMN reply_to_message_id BIGINT NULL;

ALTER TABLE direct_messages
    ADD CONSTRAINT direct_messages_reply_to_paired_check CHECK (
        (reply_to_message_kind IS NULL) = (reply_to_message_id IS NULL)
    );

CREATE TABLE room_message_mentions (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES room_messages(id) ON DELETE CASCADE,
    mentioned_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    broadcast BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (message_id, mentioned_user_id)
);

CREATE INDEX room_message_mentions_user_timeline
    ON room_message_mentions (mentioned_user_id, id DESC);

CREATE TABLE direct_message_mentions (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES direct_messages(id) ON DELETE CASCADE,
    mentioned_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (message_id, mentioned_user_id)
);

CREATE INDEX direct_message_mentions_user_timeline
    ON direct_message_mentions (mentioned_user_id, id DESC);

ALTER TABLE account_notifications
    DROP CONSTRAINT IF EXISTS account_notifications_kind_check;

ALTER TABLE account_notifications
    ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
        'revision_review',
        'moderator_message_deleted',
        'admin_password_reset',
        'system_policy_changed',
        'mentioned'
    ));

COMMENT ON COLUMN room_messages.reply_to_message_id IS
    'Canonical reference to the message this reply targets. Never cascades: retention removing the target leaves the reply intact with an unresolved preview.';
COMMENT ON COLUMN direct_messages.reply_to_message_id IS
    'Canonical reference to the message this reply targets. Never cascades: retention removing the target leaves the reply intact with an unresolved preview.';
COMMENT ON TABLE room_message_mentions IS
    'Mentions resolved and authorized at send time. Cascades with its message, unlike reply references. broadcast marks resolution via @room/@here rather than an explicit @username.';
COMMENT ON TABLE direct_message_mentions IS
    'Mentions resolved and authorized at send time; the only possible mentioned account is the conversation recipient. Cascades with its message.';
