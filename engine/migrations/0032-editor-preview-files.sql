-- cms:statement
CREATE TABLE IF NOT EXISTS cms_editor_preview_files (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (token_hash),
    KEY cms_editor_preview_session (session_id, created_at, token_hash),
    KEY cms_editor_preview_expiry (expires_at),
    CONSTRAINT cms_editor_preview_build_fk FOREIGN KEY (preview_id) REFERENCES cms_previews (id) ON DELETE CASCADE,
    CONSTRAINT cms_editor_preview_session_fk FOREIGN KEY (session_id) REFERENCES cms_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
