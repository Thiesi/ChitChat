-- Members who never sent a read position start fully read, as 0033 did for the
-- members it found (#268). New memberships get theirs when they are created.
INSERT INTO room_reads (user_id, room_id, last_read_message_id)
SELECT rm.user_id,
       rm.room_id,
       COALESCE((SELECT MAX(m.id) FROM room_messages m WHERE m.room_id = rm.room_id), 0)
FROM room_members rm
ON CONFLICT (user_id, room_id) DO NOTHING;
