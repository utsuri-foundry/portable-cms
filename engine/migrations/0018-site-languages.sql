-- cms:statement
CREATE TABLE IF NOT EXISTS cms_site_languages (
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    revision INT UNSIGNED NOT NULL,
    document_json LONGTEXT NOT NULL,
    CONSTRAINT cms_site_languages_json_check CHECK (JSON_VALID(document_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
