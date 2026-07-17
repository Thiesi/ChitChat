CREATE TABLE direct_messages (
    id BIGSERIAL PRIMARY KEY,
    sender_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipient_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 4000),
    recipient_read_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (sender_user_id <> recipient_user_id)
);

CREATE INDEX direct_messages_conversation_history
    ON direct_messages (
        LEAST(sender_user_id, recipient_user_id),
        GREATEST(sender_user_id, recipient_user_id),
        id DESC
    );

CREATE INDEX direct_messages_unread_recipient
    ON direct_messages (recipient_user_id, id DESC)
    WHERE recipient_read_at IS NULL;

ALTER TABLE realtime_events
    DROP CONSTRAINT IF EXISTS realtime_events_event_type_check;

ALTER TABLE realtime_events
    ADD CONSTRAINT realtime_events_event_type_check CHECK (event_type IN (
        'room_message',
        'message_deleted',
        'ping',
        'room_broadcast',
        'global_broadcast',
        'forced_logout',
        'presence_changed',
        'direct_message'
    ));
