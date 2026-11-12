-- cms:statement
ALTER TABLE cms_records
    ADD COLUMN editing_session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN editing_saved_at DATETIME(6) NULL,
    ADD COLUMN editing_request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
