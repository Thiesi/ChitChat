-- The name the installation presents to people, editable by Super-Administrators.
-- NULL means "use the server default" from APP_NAME.
ALTER TABLE system_settings
    ADD COLUMN application_name VARCHAR(64) NULL
        CHECK (application_name IS NULL OR char_length(btrim(application_name)) BETWEEN 1 AND 64);
