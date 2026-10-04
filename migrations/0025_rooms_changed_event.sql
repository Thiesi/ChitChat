-- A contentless signal that a viewer's room list may have changed (a room was
-- created, updated or deleted, or the viewer's membership or invitation
-- changed). Clients re-fetch the authorized room list in response.
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
        'rooms_changed'
    ));
