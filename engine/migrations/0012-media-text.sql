-- cms:statement
ALTER TABLE cms_media ADD COLUMN text_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    ADD CONSTRAINT cms_media_text_json_check CHECK (text_json IS NULL OR JSON_VALID(text_json))
