-- cms:statement
CREATE TABLE IF NOT EXISTS cms_media (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    declared_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cms_media_original_key_unique (original_key),
    KEY cms_media_owner_created_index (owner_record_id, created_at, id),
    CONSTRAINT cms_media_owner_fk FOREIGN KEY (owner_record_id) REFERENCES cms_records (id),
    CONSTRAINT cms_media_created_by_fk FOREIGN KEY (created_by) REFERENCES cms_users (id),
    CONSTRAINT cms_media_status_check CHECK (status IN ('uploaded', 'processing', 'ready', 'needs_attention')),
    CONSTRAINT cms_media_byte_size_check CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_jobs (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY cms_jobs_claim_index (job_type, status, available_at, created_at, id),
    CONSTRAINT cms_jobs_status_check CHECK (status IN ('queued', 'running', 'retry', 'completed', 'needs_attention')),
    CONSTRAINT cms_jobs_attempts_check CHECK (attempts <= max_attempts),
    CONSTRAINT cms_jobs_payload_json_check CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_media_upload_sessions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_upload_id VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    active_client_key BINARY(32) NULL,
    owner_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    declared_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    part_size INT UNSIGNED NOT NULL,
    part_count SMALLINT UNSIGNED NOT NULL,
    initial_record_revision INT UNSIGNED NOT NULL,
    media_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cms_media_upload_active_client_unique (active_client_key),
    UNIQUE KEY cms_media_upload_object_key_unique (object_key),
    KEY cms_media_upload_expiry_index (status, expires_at),
    KEY cms_media_upload_owner_index (owner_record_id, created_at, id),
    CONSTRAINT cms_media_upload_owner_fk FOREIGN KEY (owner_record_id) REFERENCES cms_records (id),
    CONSTRAINT cms_media_upload_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_media_upload_media_fk FOREIGN KEY (media_id) REFERENCES cms_media (id),
    CONSTRAINT cms_media_upload_job_fk FOREIGN KEY (job_id) REFERENCES cms_jobs (id),
    CONSTRAINT cms_media_upload_status_check CHECK (status IN ('uploading', 'completing', 'completed', 'aborted', 'expired')),
    CONSTRAINT cms_media_upload_size_check CHECK (byte_size > 0 AND part_size > 0 AND part_count > 0),
    CONSTRAINT cms_media_upload_revision_check CHECK (initial_record_revision > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_media_upload_parts (
    session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    part_number SMALLINT UNSIGNED NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (session_id, part_number),
    CONSTRAINT cms_media_upload_parts_session_fk FOREIGN KEY (session_id)
        REFERENCES cms_media_upload_sessions (id) ON DELETE CASCADE,
    CONSTRAINT cms_media_upload_parts_number_check CHECK (part_number > 0),
    CONSTRAINT cms_media_upload_parts_size_check CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
