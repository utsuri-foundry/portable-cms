-- cms:statement
ALTER TABLE cms_releases DROP CONSTRAINT cms_release_state;

-- cms:statement
ALTER TABLE cms_releases
    ADD COLUMN scheduled_at DATETIME(6) NULL,
    ADD COLUMN schedule_revision INT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY cms_release_due (status, scheduled_at, id),
    ADD CONSTRAINT cms_release_state CHECK (status IN ('scheduled', 'needs_approval', 'cancelled', 'queued', 'applying', 'recovering', 'needs_attention', 'active', 'retained', 'failed'));
