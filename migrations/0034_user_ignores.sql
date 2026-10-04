-- People someone has chosen to ignore in rooms: their room messages are
-- collapsed and their mentions and pings stop notifying, for that person
-- only. The ignored person is never told. Direct messages have blocking.
CREATE TABLE user_ignores (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ignored_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, ignored_user_id),
    CHECK (user_id <> ignored_user_id)
);

CREATE INDEX user_ignores_ignored ON user_ignores (ignored_user_id);
