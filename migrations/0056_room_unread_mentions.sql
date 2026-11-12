-- Per-room unread mention counts for the room list (#266).
-- Only unread room-mention notifications are indexed, so each joined room costs one
-- short index range however many other (or read) notifications the account has.
-- Keyed on the room_id text rather than a cast, so no notification insert can fail here.
CREATE INDEX account_notifications_unread_room_mentions
    ON account_notifications (user_id, (context_json->>'room_id'))
    WHERE kind = 'mentioned' AND read_at IS NULL AND context_json->>'message_kind' = 'room';
