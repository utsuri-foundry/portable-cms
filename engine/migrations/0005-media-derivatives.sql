-- cms:statement
ALTER TABLE cms_media
    ADD COLUMN detected_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER declared_type,
    ADD COLUMN width INT UNSIGNED NULL AFTER byte_size,
    ADD COLUMN height INT UNSIGNED NULL AFTER width,
    ADD COLUMN error_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER original_key,
    ADD CONSTRAINT cms_media_geometry_check CHECK (
        (width IS NULL AND height IS NULL) OR (width > 0 AND height > 0)
    )

-- cms:statement
ALTER TABLE cms_jobs
    ADD COLUMN lease_owner VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER available_at,
    ADD COLUMN lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER lease_owner,
    ADD COLUMN lease_expires_at DATETIME(6) NULL AFTER lease_token,
    ADD COLUMN progress_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER lease_expires_at,
    ADD COLUMN result_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER progress_json,
    ADD COLUMN error_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER result_json,
    ADD UNIQUE KEY cms_jobs_lease_token_unique (lease_token),
    ADD CONSTRAINT cms_jobs_progress_json_check CHECK (progress_json IS NULL OR JSON_VALID(progress_json)),
    ADD CONSTRAINT cms_jobs_result_json_check CHECK (result_json IS NULL OR JSON_VALID(result_json))

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_media_derivatives (
    media_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    variant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    filename VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processor_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processor_version VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    recipe_version SMALLINT UNSIGNED NOT NULL,
    parameters_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (media_id, variant),
    UNIQUE KEY cms_media_derivatives_object_key_unique (object_key),
    CONSTRAINT cms_media_derivatives_media_fk FOREIGN KEY (media_id)
        REFERENCES cms_media (id) ON DELETE CASCADE,
    CONSTRAINT cms_media_derivatives_size_check CHECK (
        width > 0 AND height > 0 AND byte_size > 0 AND recipe_version > 0
    ),
    CONSTRAINT cms_media_derivatives_parameters_json_check CHECK (JSON_VALID(parameters_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
