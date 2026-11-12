-- cms:statement
CREATE TABLE IF NOT EXISTS cms_preview_follow_links (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY cms_preview_follow_expiry (expires_at),
    CONSTRAINT cms_preview_follow_actor FOREIGN KEY (created_by) REFERENCES cms_users (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_preview_follow_resolutions (
    link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resolved_at DATETIME(6) NOT NULL,
    PRIMARY KEY (link_id, preview_id),
    CONSTRAINT cms_preview_follow_link FOREIGN KEY (link_id) REFERENCES cms_preview_follow_links (id) ON DELETE CASCADE,
    CONSTRAINT cms_preview_follow_preview FOREIGN KEY (preview_id) REFERENCES cms_previews (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE INDEX cms_previews_newest_ready ON cms_previews (build_kind, status, created_at, id)
