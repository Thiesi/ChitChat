-- The maintenance max age as an Operational setting (#312).
--
-- NULL means "not set in ChitChat": the MAINTENANCE_MAX_AGE_HOURS environment value (default
-- 26) applies as the fallback, so an upgrade changes nothing. Once saved in Operational
-- settings, this value wins; saving null clears it again. A migration can't read the
-- environment, and seeding it on first read would be a write from a read path, so it stays NULL.
ALTER TABLE system_settings
    ADD COLUMN maintenance_max_age_hours INTEGER NULL
        CHECK (maintenance_max_age_hours BETWEEN 1 AND 720);
