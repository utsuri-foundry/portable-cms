-- cms:statement
CREATE TABLE IF NOT EXISTS cms_live_previews (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    render_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_key VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (token_hash, render_id),
    KEY cms_live_previews_session_index (session_id, record_key, created_at),
    KEY cms_live_previews_expiry_index (expires_at),
    CONSTRAINT cms_live_previews_session_fk FOREIGN KEY (session_id) REFERENCES cms_sessions (id) ON DELETE CASCADE,
    CONSTRAINT cms_live_previews_document_json_check CHECK (JSON_VALID(document_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
