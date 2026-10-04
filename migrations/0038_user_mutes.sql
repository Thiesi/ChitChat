-- Muting: the person can still sign in and read, but not post. A mute
-- applies in one room (room_id) or everywhere (room_id NULL, which also
-- stops sending direct messages). It ends when it expires or is lifted;
-- ended mutes stay as the record.
CREATE TABLE user_mutes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    room_id BIGINT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    muted_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NULL,
    lifted_at TIMESTAMPTZ NULL,
    lifted_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    CHECK (expires_at IS NULL OR expires_at > created_at)
);

CREATE INDEX user_mutes_current ON user_mutes (user_id, room_id) WHERE lifted_at IS NULL;

-- The muted person is told why and until when.
ALTER TABLE account_notifications
    DROP CONSTRAINT IF EXISTS account_notifications_kind_check;

ALTER TABLE account_notifications
    ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
        'revision_review',
        'moderator_message_deleted',
        'admin_password_reset',
        'system_policy_changed',
        'mentioned',
        'pinged',
        'room_deleted',
        'room_restored',
        'avatar_removed',
        'muted'
    ));
