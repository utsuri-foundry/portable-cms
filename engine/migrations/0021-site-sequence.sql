-- cms:statement
CREATE TABLE IF NOT EXISTS cms_site_sequence (
    singleton_id TINYINT UNSIGNED PRIMARY KEY,
    current_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    pruned_through BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT cms_site_sequence_singleton CHECK (singleton_id = 1),
    CONSTRAINT cms_site_sequence_range CHECK (pruned_through <= current_sequence AND current_sequence <= 9007199254740991)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- cms:statement
INSERT IGNORE INTO cms_site_sequence (singleton_id) VALUES (1)

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_site_changes (
    sequence BIGINT UNSIGNED PRIMARY KEY,
    kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_type VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
    record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision_number INT UNSIGNED NULL,
    activation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    occurred_at DATETIME(6) NOT NULL,
    CONSTRAINT cms_site_changes_sequence CHECK (sequence >= 1 AND sequence <= 9007199254740991),
    CONSTRAINT cms_site_changes_kind CHECK (kind IN ('record_revision', 'record_removed', 'release_activated')),
    CONSTRAINT cms_site_changes_identity CHECK (
        (kind IN ('record_revision', 'record_removed') AND record_type IS NOT NULL AND record_id IS NOT NULL AND revision_id IS NOT NULL AND revision_number IS NOT NULL AND revision_number >= 1 AND activation_id IS NULL AND preview_id IS NULL)
        OR (kind = 'release_activated' AND record_type IS NULL AND record_id IS NULL AND revision_id IS NULL AND revision_number IS NULL AND activation_id IS NOT NULL AND preview_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- cms:statement
ALTER TABLE cms_record_revisions ADD COLUMN IF NOT EXISTS site_sequence BIGINT UNSIGNED NULL,
    ADD UNIQUE INDEX IF NOT EXISTS cms_record_revisions_site_sequence (site_sequence)

-- cms:statement
ALTER TABLE cms_releases ADD COLUMN IF NOT EXISTS site_sequence BIGINT UNSIGNED NULL,
    ADD UNIQUE INDEX IF NOT EXISTS cms_releases_site_sequence (site_sequence)
