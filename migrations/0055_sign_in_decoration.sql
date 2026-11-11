-- Seasonal decoration on the signed-out sign-in screen, chosen by hand in the admin UI (#242).
-- No date windows: hemispheres and calendars differ.
ALTER TABLE system_settings
    ADD COLUMN sign_in_decoration text NOT NULL DEFAULT 'off',
    ADD CONSTRAINT system_settings_sign_in_decoration_check
        CHECK (sign_in_decoration IN ('off', 'winter', 'spring', 'celebration'));
