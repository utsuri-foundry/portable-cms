-- cms:statement
CREATE TABLE IF NOT EXISTS cms_previews (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    manifest_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    error_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cms_previews_job_unique (job_id),
    KEY cms_previews_created_index (created_at, id),
    CONSTRAINT cms_previews_job_fk FOREIGN KEY (job_id) REFERENCES cms_jobs (id),
    CONSTRAINT cms_previews_created_by_fk FOREIGN KEY (created_by) REFERENCES cms_users (id),
    CONSTRAINT cms_previews_status_check CHECK (
        status IN ('queued', 'building', 'ready', 'needs_attention')
    ),
    CONSTRAINT cms_previews_snapshot_json_check CHECK (JSON_VALID(snapshot_json)),
    CONSTRAINT cms_previews_manifest_json_check CHECK (
        manifest_json IS NULL OR JSON_VALID(manifest_json)
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
