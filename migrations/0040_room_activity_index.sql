-- Room-list recency reads one visible timestamp per joined room, without
-- scanning the entire retained history or fetching message contents.
CREATE INDEX room_messages_latest_visible
    ON room_messages (room_id, created_at DESC)
    WHERE deleted_at IS NULL;
