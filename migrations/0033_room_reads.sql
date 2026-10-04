-- How far each member has read in each room, for unread counts in the room
-- list and the "New messages" divider. Existing members start fully read.
CREATE TABLE room_reads (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    last_read_message_id BIGINT NOT NULL DEFAULT 0 CHECK (last_read_message_id >= 0),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, room_id)
);

INSERT INTO room_reads (user_id, room_id, last_read_message_id)
SELECT rm.user_id, rm.room_id, COALESCE(MAX(m.id), 0)
FROM room_members rm
LEFT JOIN room_messages m ON m.room_id = rm.room_id
GROUP BY rm.user_id, rm.room_id;
