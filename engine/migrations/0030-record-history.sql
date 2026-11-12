-- cms:statement
ALTER TABLE cms_records
    ADD COLUMN history_cleanup_revision INT UNSIGNED NULL,
    ADD KEY cms_records_history_cleanup_index (history_cleanup_revision, id)

-- cms:statement
CREATE TABLE cms_record_history_settings (
    id TINYINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    history_limit SMALLINT UNSIGNED NOT NULL,
    pending BOOLEAN NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT cms_record_history_singleton_check CHECK (id = 1),
    CONSTRAINT cms_record_history_limit_check CHECK (history_limit BETWEEN 10 AND 500)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
INSERT INTO cms_record_history_settings (id, revision, history_limit, pending) VALUES (1, 0, 50, FALSE)
