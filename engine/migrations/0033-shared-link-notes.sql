-- cms:statement
ALTER TABLE cms_preview_shares
    ADD COLUMN note VARCHAR(120) NULL,
    ADD COLUMN sealed_secret VARBINARY(255) NULL
-- cms:statement
ALTER TABLE cms_preview_follow_links
    ADD COLUMN note VARCHAR(120) NULL,
    ADD COLUMN sealed_secret VARBINARY(255) NULL
