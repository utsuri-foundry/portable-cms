-- cms:statement
ALTER TABLE cms_users ADD COLUMN interface_language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD CONSTRAINT cms_users_interface_language_check CHECK (interface_language IS NULL OR interface_language IN ('ca', 'es', 'en', 'fr', 'de'))
