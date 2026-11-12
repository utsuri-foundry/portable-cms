-- cms:statement
CREATE TABLE IF NOT EXISTS cms_ai_quota (
    quota_day DATE NOT NULL PRIMARY KEY,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    reserved_micros BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB

-- cms:statement
CREATE TABLE IF NOT EXISTS cms_ai_jobs (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    client_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    candidate_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    lease_expires_at DATETIME(6) NULL,
    available_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    error_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    KEY cms_ai_jobs_claim (status, available_at, expires_at),
    KEY cms_ai_jobs_actor_record (actor_id, record_id, created_at, id),
    CONSTRAINT cms_ai_actor_fk FOREIGN KEY (actor_id) REFERENCES cms_users(id),
    CONSTRAINT cms_ai_record_fk FOREIGN KEY (record_id) REFERENCES cms_records(id),
    CONSTRAINT cms_ai_request_json CHECK (request_json IS NULL OR JSON_VALID(request_json)),
    CONSTRAINT cms_ai_candidate_json CHECK (candidate_json IS NULL OR JSON_VALID(candidate_json)),
    CONSTRAINT cms_ai_status CHECK (status IN ('queued','running','retry','completed','failed','cancelled','expired')),
    CONSTRAINT cms_ai_attempts CHECK (attempts <= 2)
) ENGINE=InnoDB
