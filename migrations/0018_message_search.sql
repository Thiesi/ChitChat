CREATE INDEX room_messages_body_search
    ON room_messages
    USING GIN (to_tsvector('simple', body))
    WHERE deleted_at IS NULL;

CREATE INDEX direct_messages_body_search
    ON direct_messages
    USING GIN (to_tsvector('simple', body))
    WHERE deleted_at IS NULL;
