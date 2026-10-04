-- "Alex is typing…": a short-lived signal to the room's members, or to the
-- other person in a direct conversation. It expires within seconds and
-- carries no message text.
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
        'direct_message',
        'message_reaction_changed',
        'rooms_changed',
        'typing'
    ));
