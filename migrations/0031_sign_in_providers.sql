-- Sign-in with Google and Twitch (OpenID Connect). ChitChat asks only for the
-- "openid" scope and stores the provider and its stable subject identifier,
-- never an email address or profile data.
CREATE TABLE user_identities (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider VARCHAR(16) NOT NULL CHECK (provider IN ('google', 'twitch')),
    subject VARCHAR(255) NOT NULL CHECK (char_length(subject) >= 1),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ NULL,
    UNIQUE (provider, subject),
    UNIQUE (user_id, provider)
);

COMMENT ON TABLE user_identities IS
    'A sign-in provider account connected to a ChitChat account: the provider and its OpenID Connect subject only. Deleted when the account is permanently closed.';

-- Providers'' signing keys, refreshed hourly or when an unknown key ID appears.
CREATE TABLE oidc_signing_keys (
    provider VARCHAR(16) PRIMARY KEY CHECK (provider IN ('google', 'twitch')),
    jwks_json JSONB NOT NULL,
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
