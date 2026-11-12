-- cms:statement
ALTER TABLE cms_releases ADD COLUMN is_rollback TINYINT(1) NOT NULL DEFAULT 0;

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_feed_state (
    singleton_id TINYINT UNSIGNED PRIMARY KEY,
    dirty TINYINT(1) NOT NULL DEFAULT 0,
    cursor_floor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT cms_feed_singleton CHECK (singleton_id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cms:statement
INSERT IGNORE INTO cms_feed_state (singleton_id) VALUES (1);

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_feed_snapshots (
    id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    manifest_json LONGTEXT NOT NULL,
    files_json LONGTEXT NOT NULL,
    purged TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_feed_activations (
    sequence BIGINT UNSIGNED PRIMARY KEY,
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    snapshot_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activated_at DATETIME(6) NOT NULL,
    document_json LONGTEXT NOT NULL,
    CONSTRAINT cms_feed_snapshot FOREIGN KEY (snapshot_id) REFERENCES cms_feed_snapshots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
