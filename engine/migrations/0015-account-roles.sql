-- cms:statement
ALTER TABLE cms_users DROP CONSTRAINT cms_users_role_check

-- cms:statement
ALTER TABLE cms_users
    ADD CONSTRAINT cms_users_role_check CHECK (role IN ('owner', 'administrator', 'editor')),
    ADD COLUMN must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN security_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN owner_slot TINYINT GENERATED ALWAYS AS (CASE WHEN role = 'owner' THEN 1 ELSE NULL END) STORED,
    ADD UNIQUE KEY cms_users_one_owner (owner_slot),
    ADD CONSTRAINT cms_users_owner_active CHECK (role <> 'owner' OR status = 'active')

-- cms:statement
UPDATE cms_users SET role = 'owner'
WHERE role = 'administrator' AND status = 'active'
ORDER BY created_at, id LIMIT 1

-- cms:statement
ALTER TABLE cms_sessions ADD COLUMN security_version INT UNSIGNED NOT NULL DEFAULT 0

-- cms:statement
CREATE TABLE cms_owner_recoveries (
    gate_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (gate_hash)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
