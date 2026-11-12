#!/bin/sh

set -eu

state="$(mktemp -d)"
data="$state/data"
socket="$state/mariadb.sock"
pid_file="$state/mariadb.pid"
log="$state/mariadb.log"

cleanup() {
  if [ -f "$pid_file" ]; then
    mariadb-admin --no-defaults --socket="$socket" --user=root shutdown >/dev/null 2>&1 || true
  fi
  rm -rf "$state"
}
trap cleanup EXIT HUP INT TERM

mariadb-install-db \
  --no-defaults \
  --auth-root-authentication-method=normal \
  --skip-test-db \
  --datadir="$data" >/dev/null

mariadbd \
  --no-defaults \
  --datadir="$data" \
  --socket="$socket" \
  --pid-file="$pid_file" \
  --log-error="$log" \
  --skip-networking &

attempt=0
until mariadb-admin --no-defaults --socket="$socket" --user=root ping >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 100 ]; then
    sed -n '1,160p' "$log" >&2
    exit 1
  fi
  sleep 0.1
done

mariadb --no-defaults --socket="$socket" --user=root --execute="
CREATE DATABASE portable_cms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_sequence CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_install CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_journey CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_workflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_history CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_editor_exact CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_publication CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_holding CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_sandbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_restore_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_restore_destination CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_import CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_upgrade_current CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_upgrade_standby CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_prefix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_accounts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_identity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_website_media CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_growth_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_growth_destination CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_transfer_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_transfer_destination CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_transfer_resume CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE portable_transfer_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portable'@'localhost' IDENTIFIED BY 'integration-secret';
GRANT ALL PRIVILEGES ON portable_cms.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_history.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_editor_exact.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_identity.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_website_media.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_growth_source.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_growth_destination.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_transfer_source.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_transfer_destination.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_transfer_resume.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_transfer_legacy.* TO 'portable'@'localhost';

GRANT ALL PRIVILEGES ON portable_sequence.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_install.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_journey.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_workflow.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_publication.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_holding.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_sandbox.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_restore_source.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_restore_destination.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_import.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_upgrade_current.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_upgrade_standby.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_prefix.* TO 'portable'@'localhost';
GRANT ALL PRIVILEGES ON portable_accounts.* TO 'portable'@'localhost';
"

if [ "${CMS_TEST_PREVIEW_WAKE_ONLY:-0}" = 1 ]; then
  CMS_TEST_EDITOR_EXACT_DSN="mysql:unix_socket=$socket;dbname=portable_editor_exact;charset=utf8mb4" \
    php tests/php/preview-wake-integration.php
  exit 0
fi

if [ "${CMS_TEST_EDITOR_EXACT_ONLY:-0}" = 1 ]; then
  CMS_TEST_EDITOR_EXACT_DSN="mysql:unix_socket=$socket;dbname=portable_editor_exact;charset=utf8mb4" \
    php tests/php/editor-exact-preview-integration.php
  CMS_TEST_EDITOR_EXACT_DSN="mysql:unix_socket=$socket;dbname=portable_editor_exact;charset=utf8mb4" \
    php tests/php/preview-wake-integration.php
  exit 0
fi

if [ "${CMS_TEST_HISTORY_ONLY:-0}" = 1 ]; then
  CMS_TEST_HISTORY_DSN="mysql:unix_socket=$socket;dbname=portable_history;charset=utf8mb4" \
    php tests/php/record-history-integration.php
  exit 0
fi

if [ "${CMS_TEST_WEBSITE_MEDIA_ONLY:-0}" = 1 ]; then
  CMS_TEST_WEBSITE_MEDIA_DSN="mysql:unix_socket=$socket;dbname=portable_website_media;charset=utf8mb4" \
    php tests/php/website-media-integration.php
  exit 0
fi

if [ "${CMS_TEST_IMPORT_ONLY:-0}" = 1 ]; then
  CMS_TEST_IMPORT_DSN="mysql:unix_socket=$socket;dbname=portable_import;charset=utf8mb4" \
    php tests/php/content-import-integration.php
  exit 0
fi

if [ "${CMS_TEST_TRANSFER_ONLY:-0}" = 1 ]; then
  CMS_TEST_TRANSFER_SOURCE_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_source;charset=utf8mb4" \
  CMS_TEST_TRANSFER_DESTINATION_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_destination;charset=utf8mb4" \
  CMS_TEST_TRANSFER_RESUME_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_resume;charset=utf8mb4" \
  CMS_TEST_TRANSFER_LEGACY_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_legacy;charset=utf8mb4" \
    php tests/php/portable-transfer-integration.php
  exit 0
fi

if [ "${CMS_TEST_GROWTH_ONLY:-0}" = 1 ]; then
  CMS_TEST_GROWTH_SOURCE_DSN="mysql:unix_socket=$socket;dbname=portable_growth_source;charset=utf8mb4" \
  CMS_TEST_GROWTH_DESTINATION_DSN="mysql:unix_socket=$socket;dbname=portable_growth_destination;charset=utf8mb4" \
    php -d memory_limit=256M tests/php/export-growth-integration.php
  exit 0
fi

if [ "${CMS_TEST_RECORD_KEEPING_ONLY:-0}" = 1 ]; then
  CMS_TEST_SITE_SEQUENCE_DSN="mysql:unix_socket=$socket;dbname=portable_sequence;charset=utf8mb4" \
    php tests/php/record-keeping-integration.php
  CMS_TEST_WORKFLOW_DSN="mysql:unix_socket=$socket;dbname=portable_workflow;charset=utf8mb4" \
    php tests/php/record-workflow-integration.php
  exit 0
fi

if [ "${CMS_TEST_SITE_SEQUENCE_ONLY:-0}" = 1 ]; then
  CMS_TEST_SITE_SEQUENCE_DSN="mysql:unix_socket=$socket;dbname=portable_sequence;charset=utf8mb4" \
    php tests/php/site-sequence-integration.php
  exit 0
fi

if [ "${CMS_TEST_RESTORE_ONLY:-0}" = 1 ]; then
  CMS_TEST_RESTORE_SOURCE_DSN="mysql:unix_socket=$socket;dbname=portable_restore_source;charset=utf8mb4" \
  CMS_TEST_RESTORE_DESTINATION_DSN="mysql:unix_socket=$socket;dbname=portable_restore_destination;charset=utf8mb4" \
    php tests/php/normalized-restore-integration.php
  exit 0
fi

if [ "${CMS_TEST_RESPONSIVE_IMAGES_ONLY:-0}" = 1 ]; then
  CMS_TEST_SANDBOX_DSN="mysql:unix_socket=$socket;dbname=portable_sandbox;charset=utf8mb4" \
    php tests/php/reference-cms-sandbox-integration.php
  CMS_TEST_SANDBOX_DSN="mysql:unix_socket=$socket;dbname=portable_sandbox;charset=utf8mb4" \
    php tests/php/reference-preview-integration.php
  exit 0
fi

if [ "${CMS_TEST_ACCOUNTS_ONLY:-0}" = 1 ]; then
  CMS_TEST_ACCOUNT_DSN="mysql:unix_socket=$socket;dbname=portable_accounts;charset=utf8mb4" \
    php tests/php/account-integration.php
  exit 0
fi

if [ "${CMS_TEST_PUBLICATION_ONLY:-0}" = 1 ]; then
  CMS_TEST_PUBLICATION_DSN="mysql:unix_socket=$socket;dbname=portable_publication;charset=utf8mb4" \
    php tests/php/publication-integration.php
  exit 0
fi

if [ "${CMS_TEST_UPGRADE_ONLY:-0}" = 1 ]; then
  CMS_TEST_UPGRADE_DSN="mysql:unix_socket=$socket;dbname=portable_upgrade_current;charset=utf8mb4" \
  CMS_TEST_UPGRADE_STANDBY_DSN="mysql:unix_socket=$socket;dbname=portable_upgrade_standby;charset=utf8mb4" \
    php tests/php/upgrade-integration.php
  exit 0
fi

CMS_TEST_WEBSITE_MEDIA_DSN="mysql:unix_socket=$socket;dbname=portable_website_media;charset=utf8mb4" \
  php tests/php/website-media-integration.php

CMS_TEST_IDENTITY_DSN="mysql:unix_socket=$socket;dbname=portable_identity;charset=utf8mb4" \
  php tests/php/website-identity-integration.php
if [ "${CMS_TEST_IDENTITY_ONLY:-0}" = 1 ]; then
  exit 0
fi

CMS_TEST_SITE_SEQUENCE_DSN="mysql:unix_socket=$socket;dbname=portable_sequence;charset=utf8mb4" \
  php tests/php/site-sequence-integration.php

CMS_TEST_SITE_SEQUENCE_DSN="mysql:unix_socket=$socket;dbname=portable_sequence;charset=utf8mb4" \
  php tests/php/record-keeping-integration.php

CMS_TEST_DB_DSN="mysql:unix_socket=$socket;dbname=portable_cms;charset=utf8mb4" \
CMS_TEST_INSTALL_DSN="mysql:unix_socket=$socket;dbname=portable_install;charset=utf8mb4" \
  php tests/php/mariadb-integration.php

CMS_TEST_ACCOUNT_DSN="mysql:unix_socket=$socket;dbname=portable_accounts;charset=utf8mb4" \
  php tests/php/account-integration.php

CMS_TEST_JOURNEY_DSN="mysql:unix_socket=$socket;dbname=portable_journey;charset=utf8mb4" \
  php tests/php/http-editorial-journey.php

CMS_TEST_WORKFLOW_DSN="mysql:unix_socket=$socket;dbname=portable_workflow;charset=utf8mb4" \
  php tests/php/record-workflow-integration.php

CMS_TEST_HISTORY_DSN="mysql:unix_socket=$socket;dbname=portable_history;charset=utf8mb4" \
  php tests/php/record-history-integration.php

CMS_TEST_EDITOR_EXACT_DSN="mysql:unix_socket=$socket;dbname=portable_editor_exact;charset=utf8mb4" \
  php tests/php/editor-exact-preview-integration.php
CMS_TEST_EDITOR_EXACT_DSN="mysql:unix_socket=$socket;dbname=portable_editor_exact;charset=utf8mb4" \
  php tests/php/preview-wake-integration.php

CMS_TEST_PUBLICATION_DSN="mysql:unix_socket=$socket;dbname=portable_publication;charset=utf8mb4" \
  php tests/php/publication-integration.php

CMS_TEST_HOLDING_DSN="mysql:unix_socket=$socket;dbname=portable_holding;charset=utf8mb4" \
  php tests/php/holding-publication-integration.php

CMS_TEST_SANDBOX_DSN="mysql:unix_socket=$socket;dbname=portable_sandbox;charset=utf8mb4" \
  php tests/php/reference-cms-sandbox-integration.php

CMS_TEST_SANDBOX_DSN="mysql:unix_socket=$socket;dbname=portable_sandbox;charset=utf8mb4" \
  php tests/php/reference-preview-integration.php

CMS_TEST_PREFIX_DSN="mysql:unix_socket=$socket;dbname=portable_prefix;charset=utf8mb4" \
  php tests/php/table-prefix-integration.php

CMS_TEST_RESTORE_SOURCE_DSN="mysql:unix_socket=$socket;dbname=portable_restore_source;charset=utf8mb4" \
CMS_TEST_RESTORE_DESTINATION_DSN="mysql:unix_socket=$socket;dbname=portable_restore_destination;charset=utf8mb4" \
  php tests/php/normalized-restore-integration.php

CMS_TEST_IMPORT_DSN="mysql:unix_socket=$socket;dbname=portable_import;charset=utf8mb4" \
  php tests/php/content-import-integration.php
CMS_TEST_UPGRADE_DSN="mysql:unix_socket=$socket;dbname=portable_upgrade_current;charset=utf8mb4" \
CMS_TEST_UPGRADE_STANDBY_DSN="mysql:unix_socket=$socket;dbname=portable_upgrade_standby;charset=utf8mb4" \
  php tests/php/upgrade-integration.php

CMS_TEST_GROWTH_SOURCE_DSN="mysql:unix_socket=$socket;dbname=portable_growth_source;charset=utf8mb4" \
CMS_TEST_GROWTH_DESTINATION_DSN="mysql:unix_socket=$socket;dbname=portable_growth_destination;charset=utf8mb4" \
  php -d memory_limit=256M tests/php/export-growth-integration.php

CMS_TEST_TRANSFER_SOURCE_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_source;charset=utf8mb4" \
CMS_TEST_TRANSFER_DESTINATION_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_destination;charset=utf8mb4" \
CMS_TEST_TRANSFER_RESUME_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_resume;charset=utf8mb4" \
CMS_TEST_TRANSFER_LEGACY_DSN="mysql:unix_socket=$socket;dbname=portable_transfer_legacy;charset=utf8mb4" \
  php tests/php/portable-transfer-integration.php
