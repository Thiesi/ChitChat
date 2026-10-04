-- A profile picture fetched from Google or Twitch at its owner's request,
-- waiting for the Account page to show it in the crop step. Kept briefly,
-- one per account, and removed as soon as the page has taken it.
CREATE TABLE avatar_imports (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    image BYTEA NOT NULL,
    media_type VARCHAR(16) NOT NULL CHECK (media_type IN ('image/jpeg', 'image/png', 'image/webp')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
