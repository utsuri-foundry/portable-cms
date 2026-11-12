-- cms:statement
CREATE TABLE IF NOT EXISTS cms_site_wording (
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    revision INT UNSIGNED NOT NULL,
    document_json LONGTEXT NOT NULL,
    CONSTRAINT cms_site_wording_json_check CHECK (JSON_VALID(document_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_site_wording_revisions (
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    document_json LONGTEXT NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (profile_id, revision),
    CONSTRAINT cms_site_wording_revision_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_site_wording_revision_json_check CHECK (JSON_VALID(document_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
