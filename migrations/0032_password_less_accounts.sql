-- Accounts created through Google or Twitch have no password until they set
-- one. They keep a random, unusable password hash (like closed accounts), so
-- every password check fails safely; this flag records the difference.
ALTER TABLE users ADD COLUMN has_password BOOLEAN NOT NULL DEFAULT TRUE;
