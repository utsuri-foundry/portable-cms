-- cms:statement
CREATE TABLE IF NOT EXISTS cms_users (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    login_name VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    display_name VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    password_changed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cms_users_login_name_unique (login_name),
    CONSTRAINT cms_users_role_check CHECK (role IN ('administrator')),
    CONSTRAINT cms_users_status_check CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_bootstrap_state (
    singleton_id TINYINT UNSIGNED NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    closed_at DATETIME(6) NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT cms_bootstrap_singleton_check CHECK (singleton_id = 1),
    CONSTRAINT cms_bootstrap_status_check CHECK (status IN ('open', 'closed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
INSERT IGNORE INTO cms_bootstrap_state (singleton_id, status, closed_at)
VALUES (1, 'open', NULL)

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_sessions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    token_hash BINARY(32) NOT NULL,
    csrf_hash BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    absolute_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cms_sessions_token_hash_unique (token_hash),
    KEY cms_sessions_user_id_index (user_id),
    KEY cms_sessions_expiry_index (expires_at, revoked_at),
    CONSTRAINT cms_sessions_user_fk FOREIGN KEY (user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_sessions_kind_check CHECK (kind IN ('anonymous', 'authenticated')),
    CONSTRAINT cms_sessions_identity_check CHECK (
        (kind = 'anonymous' AND user_id IS NULL)
        OR (kind = 'authenticated' AND user_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_login_throttles (
    key_hash BINARY(32) NOT NULL,
    failures SMALLINT UNSIGNED NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    blocked_until DATETIME(6) NULL,
    PRIMARY KEY (key_hash),
    KEY cms_login_throttles_blocked_index (blocked_until)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_audit_events (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metadata_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY cms_audit_events_actor_index (actor_user_id, occurred_at),
    KEY cms_audit_events_time_index (occurred_at),
    CONSTRAINT cms_audit_events_actor_fk FOREIGN KEY (actor_user_id) REFERENCES cms_users (id),
    CONSTRAINT cms_audit_events_metadata_json_check CHECK (JSON_VALID(metadata_json))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
