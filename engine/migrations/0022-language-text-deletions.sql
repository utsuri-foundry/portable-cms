-- cms:statement
CREATE TABLE IF NOT EXISTS cms_language_text_deletions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    locale VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    records_total INT UNSIGNED NOT NULL,
    records_done INT UNSIGNED NOT NULL,
    media_done INT UNSIGNED NOT NULL,
    started_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    KEY cms_language_text_deletions_profile_index (profile_id, started_at),
    CONSTRAINT cms_language_text_deletions_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_language_text_deletions_state_check CHECK (state IN ('running', 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
