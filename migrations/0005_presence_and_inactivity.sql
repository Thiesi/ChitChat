ALTER TABLE rooms
    ADD COLUMN inactivity_timeout_seconds INTEGER NOT NULL DEFAULT 0
        CHECK (inactivity_timeout_seconds = 0 OR inactivity_timeout_seconds BETWEEN 120 AND 86400);

CREATE TABLE room_presence (
    connection_id UUID PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    room_id BIGINT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    connected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_interaction_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    lease_expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX room_presence_active_room
    ON room_presence (room_id, lease_expires_at, user_id)
    WHERE room_id IS NOT NULL;

CREATE INDEX room_presence_user
    ON room_presence (user_id, lease_expires_at);
