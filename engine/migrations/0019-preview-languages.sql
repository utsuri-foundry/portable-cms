-- cms:statement
ALTER TABLE cms_previews
    ADD COLUMN include_preparation TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD CONSTRAINT cms_previews_preparation_check CHECK (include_preparation IN (0, 1))
