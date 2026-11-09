-- Immutable, bounded icon generations travel with the ordinary database backup.
CREATE TABLE installation_icons (
    revision text PRIMARY KEY CHECK (revision ~ '^[0-9a-f]{64}$'),
    background text NOT NULL CHECK (background ~ '^#[0-9A-F]{6}$'),
    source_width integer NOT NULL CHECK (source_width BETWEEN 512 AND 4000),
    source_height integer NOT NULL CHECK (source_height = source_width),
    maskable_scale double precision NOT NULL CHECK (maskable_scale > 0 AND maskable_scale <= 1),
    favicon bytea NOT NULL CHECK (octet_length(favicon) > 0),
    apple_touch bytea NOT NULL CHECK (octet_length(apple_touch) > 0),
    icon_192 bytea NOT NULL CHECK (octet_length(icon_192) > 0),
    icon_512 bytea NOT NULL CHECK (octet_length(icon_512) > 0),
    maskable bytea NOT NULL CHECK (octet_length(maskable) > 0),
    ico bytea NOT NULL CHECK (octet_length(ico) > 0),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    -- Attribution is an ID, not a username snapshot. Icon generations outlive account removal.
    updated_by bigint CHECK (updated_by > 0),
    CHECK (octet_length(favicon) + octet_length(apple_touch) + octet_length(icon_192)
         + octet_length(icon_512) + octet_length(maskable) + octet_length(ico) <= 4194304)
);
ALTER TABLE system_settings ADD COLUMN application_icon_revision text
    REFERENCES installation_icons(revision);
