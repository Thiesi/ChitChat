CREATE TABLE realtime_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(32) NOT NULL CHECK (event_type IN (
        'room_message',
        'message_deleted',
        'ping',
        'room_broadcast',
        'global_broadcast',
        'forced_logout'
    )),
    room_id BIGINT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    target_user_id BIGINT NULL REFERENCES users(id) ON DELETE CASCADE,
    actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NULL,
    CHECK (expires_at IS NULL OR expires_at > created_at)
);

CREATE INDEX realtime_events_cursor ON realtime_events (id);
CREATE INDEX realtime_events_target_cursor ON realtime_events (target_user_id, id)
    WHERE target_user_id IS NOT NULL;
CREATE INDEX realtime_events_room_cursor ON realtime_events (room_id, id)
    WHERE room_id IS NOT NULL;
CREATE INDEX realtime_events_expiry ON realtime_events (expires_at)
    WHERE expires_at IS NOT NULL;
