-- cms:statement
CREATE TABLE IF NOT EXISTS cms_content_import_records (
    source_namespace VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    destination_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_revision_number INT UNSIGNED NOT NULL,
    destination_revision_number INT UNSIGNED NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_namespace, source_id),
    UNIQUE KEY cms_import_destination_unique (source_namespace, destination_id),
    CONSTRAINT cms_import_record_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_content_import_media (
    source_namespace VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_metadata_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_metadata_revision INT UNSIGNED NULL,
    destination_metadata_revision INT UNSIGNED NULL,
    destination_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_upload_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_namespace, source_id, source_sha256),
    UNIQUE KEY cms_import_media_destination_unique (destination_id),
    CONSTRAINT cms_import_media_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_import_media_status_check CHECK (status IN ('pending', 'completed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
