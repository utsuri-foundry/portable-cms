-- cms:statement
CREATE TABLE IF NOT EXISTS cms_restore_state (
    singleton_id TINYINT UNSIGNED NOT NULL,
    export_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    export_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    destination_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provenance_json LONGTEXT NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT cms_restore_singleton_check CHECK (singleton_id = 1),
    CONSTRAINT cms_restore_status_check CHECK (status IN ('preparing', 'completed')),
    CONSTRAINT cms_restore_provenance_json_check CHECK (JSON_VALID(provenance_json)),
    CONSTRAINT cms_restore_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_restore_authorship (
    entity_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    restored_revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (entity_kind, entity_id),
    CONSTRAINT cms_restore_authorship_kind_check CHECK (entity_kind IN ('record', 'revision', 'media'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
