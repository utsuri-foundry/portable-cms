-- cms:statement
CREATE TABLE IF NOT EXISTS cms_preview_shares (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY cms_preview_shares_preview_index (preview_id, expires_at),
    CONSTRAINT cms_preview_shares_preview_fk FOREIGN KEY (preview_id) REFERENCES cms_previews (id) ON DELETE CASCADE,
    CONSTRAINT cms_preview_shares_actor_fk FOREIGN KEY (created_by) REFERENCES cms_users (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
