-- Image metadata is additive. Legacy rows remain null until an explicit operator backfill.
ALTER TABLE attachments
    ADD COLUMN image_width INTEGER NULL,
    ADD COLUMN image_height INTEGER NULL,
    ADD COLUMN preview_storage_key CHAR(64) NULL,
    ADD COLUMN preview_width INTEGER NULL,
    ADD COLUMN preview_height INTEGER NULL,
    ADD COLUMN preview_size_bytes BIGINT NULL,
    ADD COLUMN preview_sha256 CHAR(64) NULL,
    ADD CONSTRAINT attachments_image_dimensions CHECK (
        (image_width IS NULL AND image_height IS NULL)
        OR (image_width IS NOT NULL AND image_height IS NOT NULL AND image_width > 0 AND image_height > 0)),
    ADD CONSTRAINT attachments_preview_complete CHECK (
        (preview_storage_key IS NULL AND preview_width IS NULL AND preview_height IS NULL
            AND preview_size_bytes IS NULL AND preview_sha256 IS NULL)
        OR (preview_storage_key IS NOT NULL AND preview_storage_key ~ '^[0-9a-f]{64}$'
            AND preview_width IS NOT NULL AND preview_height IS NOT NULL AND preview_size_bytes IS NOT NULL
            AND preview_width BETWEEN 1 AND 1024 AND preview_height BETWEEN 1 AND 1024
            AND preview_size_bytes > 0 AND preview_sha256 IS NOT NULL AND preview_sha256 ~ '^[0-9a-f]{64}$'
            AND image_width IS NOT NULL AND image_height IS NOT NULL));

ALTER TABLE direct_message_attachments
    ADD COLUMN image_width INTEGER NULL,
    ADD COLUMN image_height INTEGER NULL,
    ADD COLUMN preview_storage_key CHAR(64) NULL,
    ADD COLUMN preview_width INTEGER NULL,
    ADD COLUMN preview_height INTEGER NULL,
    ADD COLUMN preview_size_bytes BIGINT NULL,
    ADD COLUMN preview_sha256 CHAR(64) NULL,
    ADD CONSTRAINT direct_attachments_image_dimensions CHECK (
        (image_width IS NULL AND image_height IS NULL)
        OR (image_width IS NOT NULL AND image_height IS NOT NULL AND image_width > 0 AND image_height > 0)),
    ADD CONSTRAINT direct_attachments_preview_complete CHECK (
        (preview_storage_key IS NULL AND preview_width IS NULL AND preview_height IS NULL
            AND preview_size_bytes IS NULL AND preview_sha256 IS NULL)
        OR (preview_storage_key IS NOT NULL AND preview_storage_key ~ '^[0-9a-f]{64}$'
            AND preview_width IS NOT NULL AND preview_height IS NOT NULL AND preview_size_bytes IS NOT NULL
            AND preview_width BETWEEN 1 AND 1024 AND preview_height BETWEEN 1 AND 1024
            AND preview_size_bytes > 0 AND preview_sha256 IS NOT NULL AND preview_sha256 ~ '^[0-9a-f]{64}$'
            AND image_width IS NOT NULL AND image_height IS NOT NULL));
