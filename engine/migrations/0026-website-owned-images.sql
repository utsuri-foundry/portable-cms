-- NULL ownership is the website itself; existing record ownership is unchanged.
ALTER TABLE cms_media
    MODIFY COLUMN owner_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD CONSTRAINT cms_media_website_image_check CHECK (owner_record_id IS NOT NULL OR declared_type IN ('image/jpeg', 'image/png', 'image/webp'));

ALTER TABLE cms_media_upload_sessions
    DROP CONSTRAINT cms_media_upload_revision_check,
    MODIFY COLUMN owner_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    MODIFY COLUMN initial_record_revision INT UNSIGNED NULL,
    ADD CONSTRAINT cms_media_upload_revision_check CHECK ((owner_record_id IS NULL AND initial_record_revision IS NULL) OR (owner_record_id IS NOT NULL AND initial_record_revision IS NOT NULL AND initial_record_revision > 0)),
    ADD CONSTRAINT cms_media_upload_website_image_check CHECK (owner_record_id IS NOT NULL OR declared_type IN ('image/jpeg', 'image/png', 'image/webp'));

ALTER TABLE cms_content_import_media
    MODIFY COLUMN owner_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;
