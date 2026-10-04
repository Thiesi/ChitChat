-- Pings become durable: each /ping is stored once and shown as a private
-- system message to its sender and target in the room where it was sent.
CREATE TABLE room_pings (
    id BIGSERIAL PRIMARY KEY,
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    sender_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL DEFAULT '' CHECK (char_length(body) <= 500),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (sender_id <> target_id)
);

CREATE INDEX room_pings_room_sender ON room_pings (room_id, sender_id, id DESC);
CREATE INDEX room_pings_room_target ON room_pings (room_id, target_id, id DESC);
CREATE INDEX room_pings_created_at ON room_pings (created_at);

COMMENT ON TABLE room_pings IS
    'One row per /ping. Visible only to its sender and target, in its room. Follows room-message retention, cascades with its room, and is kept under the tombstoned name when an account closes, like room messages.';

-- A ping reaches an offline target through the notification list and push.
-- The notification references the ping; it never copies the ping text.
ALTER TABLE account_notifications
    DROP CONSTRAINT IF EXISTS account_notifications_kind_check;

ALTER TABLE account_notifications
    ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
        'revision_review',
        'moderator_message_deleted',
        'admin_password_reset',
        'system_policy_changed',
        'mentioned',
        'pinged'
    ));

-- Pings get their own push switch next to mentions.
ALTER TABLE notification_preferences
    DROP CONSTRAINT IF EXISTS notification_preferences_category_check;

ALTER TABLE notification_preferences
    ADD CONSTRAINT notification_preferences_category_check CHECK (category IN ('mentioned', 'pinged'));
