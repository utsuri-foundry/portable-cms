-- cms:statement
ALTER TABLE cms_media
    ADD COLUMN focal_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
    ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
    ADD CONSTRAINT cms_media_focal_x_check CHECK (focal_x <= 100),
    ADD CONSTRAINT cms_media_focal_y_check CHECK (focal_y <= 100)
