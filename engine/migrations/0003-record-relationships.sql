-- cms:statement
CREATE TABLE IF NOT EXISTS cms_record_relationship_index (
    record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    field_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    relation_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (record_id, field_id, target_record_id),
    UNIQUE KEY cms_record_relationship_position_unique (record_id, field_id, position),
    KEY cms_record_relationship_target_index (target_record_id, relation_kind, record_id),
    KEY cms_record_relationship_revision_index (revision_id),
    CONSTRAINT cms_record_relationship_record_fk FOREIGN KEY (record_id) REFERENCES cms_records (id),
    CONSTRAINT cms_record_relationship_revision_fk FOREIGN KEY (revision_id) REFERENCES cms_record_revisions (id),
    CONSTRAINT cms_record_relationship_target_fk FOREIGN KEY (target_record_id) REFERENCES cms_records (id),
    CONSTRAINT cms_record_relationship_kind_check CHECK (relation_kind IN ('record', 'taxonomy'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
