-- cms:statement
CREATE TABLE IF NOT EXISTS cms_editorial_terminology (
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    labels_json LONGTEXT NOT NULL,
    PRIMARY KEY (profile_id),
    CONSTRAINT cms_terminology_json_check CHECK (JSON_VALID(labels_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_editorial_terminology_revisions (
    profile_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    labels_json LONGTEXT NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (profile_id, revision),
    CONSTRAINT cms_terminology_revision_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_terminology_revision_json_check CHECK (JSON_VALID(labels_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
