-- cms:statement
CREATE TABLE IF NOT EXISTS cms_preview_share_files (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    share_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (token_hash),
    KEY cms_preview_share_files_share_index (share_id, expires_at),
    CONSTRAINT cms_preview_share_files_share_fk FOREIGN KEY (share_id) REFERENCES cms_preview_shares (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_preview_follow_files (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (token_hash),
    KEY cms_preview_follow_files_link_index (link_id, expires_at),
    CONSTRAINT cms_preview_follow_files_resolution_fk FOREIGN KEY (link_id, preview_id) REFERENCES cms_preview_follow_resolutions (link_id, preview_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
