-- Whether this installation points its members to ChitChat for Android (#417), an Operational
-- setting. Off by default. It takes effect only once the code's store URL is set
-- (AndroidAppPromotion::STORE_URL, null until the Google Play listing is public): until then
-- nothing is shown anywhere even when this is TRUE, and the setting can't be switched on.
ALTER TABLE system_settings
    ADD COLUMN android_app_promotion boolean NOT NULL DEFAULT FALSE;
