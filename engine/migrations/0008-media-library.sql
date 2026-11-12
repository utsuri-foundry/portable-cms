-- cms:statement
ALTER TABLE cms_media ADD COLUMN metadata_revision INT UNSIGNED NOT NULL DEFAULT 1,
    ADD CONSTRAINT cms_media_metadata_revision_check CHECK (metadata_revision >= 1)
