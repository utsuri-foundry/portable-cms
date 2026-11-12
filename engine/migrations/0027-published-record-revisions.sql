-- cms:statement
ALTER TABLE cms_records
    ADD COLUMN published_revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD KEY cms_records_published_revision_index (published_revision_id)

-- cms:statement
UPDATE cms_records SET published_revision_id = current_revision_id WHERE workflow_state = 'published'

-- cms:statement
ALTER TABLE cms_records
    ADD CONSTRAINT cms_records_published_state_check CHECK (
        (workflow_state = 'published' AND published_revision_id IS NOT NULL)
        OR (workflow_state <> 'published' AND published_revision_id IS NULL)
    )

-- cms:statement
ALTER TABLE cms_record_relationship_index
    DROP PRIMARY KEY,
    DROP INDEX cms_record_relationship_position_unique,
    ADD PRIMARY KEY (record_id, revision_id, field_id, target_record_id),
    ADD UNIQUE KEY cms_record_relationship_position_unique (record_id, revision_id, field_id, position)
