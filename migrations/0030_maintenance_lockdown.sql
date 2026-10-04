-- Maintenance lockdown: while on, nobody but Super-Administrators can sign
-- in, register, or restore an account. Existing sessions stay unless the
-- Super-Administrator chose to sign everyone else out when switching it on.
ALTER TABLE system_settings
    ADD COLUMN lockdown_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN lockdown_message TEXT NULL CHECK (lockdown_message IS NULL OR char_length(lockdown_message) <= 500),
    ADD COLUMN lockdown_since TIMESTAMPTZ NULL;

COMMENT ON COLUMN system_settings.lockdown_message IS
    'Shown on the sign-in page and as a banner to signed-in users while lockdown is on; NULL uses a default text.';
