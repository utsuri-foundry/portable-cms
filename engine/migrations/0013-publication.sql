-- cms:statement
CREATE TABLE IF NOT EXISTS cms_releases (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_previous CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    artifact_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    receipt_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT cms_release_preview FOREIGN KEY (preview_id) REFERENCES cms_previews(id),
    CONSTRAINT cms_release_actor FOREIGN KEY (actor_user_id) REFERENCES cms_users(id),
    CONSTRAINT cms_release_state CHECK (status IN ('queued', 'applying', 'recovering', 'needs_attention', 'active', 'retained', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_publication_state (
    singleton_id TINYINT UNSIGNED PRIMARY KEY,
    active_release CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pending_release CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    history_json LONGTEXT NULL,
    CONSTRAINT cms_publication_singleton CHECK (singleton_id = 1),
    CONSTRAINT cms_publication_active FOREIGN KEY (active_release) REFERENCES cms_releases(id),
    CONSTRAINT cms_publication_pending FOREIGN KEY (pending_release) REFERENCES cms_releases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cms:statement
INSERT IGNORE INTO cms_publication_state (singleton_id) VALUES (1);
