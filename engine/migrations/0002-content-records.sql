-- cms:statement
CREATE TABLE IF NOT EXISTS cms_records (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    type_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    workflow_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    current_revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    current_revision_number INT UNSIGNED NOT NULL,
    created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY cms_records_type_updated_index (type_id, updated_at, id),
    KEY cms_records_current_revision_index (current_revision_id),
    CONSTRAINT cms_records_created_by_fk FOREIGN KEY (created_by) REFERENCES cms_users (id),
    CONSTRAINT cms_records_updated_by_fk FOREIGN KEY (updated_by) REFERENCES cms_users (id),
    CONSTRAINT cms_records_state_check CHECK (workflow_state IN ('draft', 'published', 'archived')),
    CONSTRAINT cms_records_revision_number_check CHECK (current_revision_number >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_record_revisions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cms_record_revisions_record_number_unique (record_id, revision_number),
    KEY cms_record_revisions_created_by_index (created_by, created_at),
    CONSTRAINT cms_record_revisions_record_fk FOREIGN KEY (record_id) REFERENCES cms_records (id),
    CONSTRAINT cms_record_revisions_created_by_fk FOREIGN KEY (created_by) REFERENCES cms_users (id),
    CONSTRAINT cms_record_revisions_number_check CHECK (revision_number >= 1),
    CONSTRAINT cms_record_revisions_body_json_check CHECK (JSON_VALID(body_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
