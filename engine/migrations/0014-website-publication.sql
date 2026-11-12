-- cms:statement
CREATE TABLE IF NOT EXISTS cms_website_publication (
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    revision INT UNSIGNED NOT NULL,
    document_json LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cms:statement
ALTER TABLE cms_previews ADD COLUMN build_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'website';
