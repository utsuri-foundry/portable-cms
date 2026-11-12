-- cms:statement
ALTER TABLE cms_previews
    ADD COLUMN include_drafts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD CONSTRAINT cms_previews_drafts_check CHECK (include_drafts IN (0, 1))
