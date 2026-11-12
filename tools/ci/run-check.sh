#!/bin/sh

set -eu
repository_root=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
cd "$repository_root"
case "${1:-}" in
  fast) exec sh tools/check-fast.sh ;;
  full) exec sh tools/check-repository.sh ;;
  qualification-*) exec node tools/ci/qualification.mjs "${1#qualification-}" ;;
  public-site-full) exec sh tests/public-site/run-public-site-audit.sh ;;
  editor-start) CMS_TEST_EDITOR_START_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  editorial) exec sh tests/browser/run-editorial-journey.sh ;;
  browser-*) exec node tools/ci/browser-cases.mjs launch "${1#browser-}" ;;
  degraded-audit) sh tests/public-site/run-degraded-site-check.sh ;;
  search-sharing) CMS_TEST_SEARCH_SHARING_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  native)
    CMS_TEST_RECORD_KEEPING_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_EDITOR_START_ONLY=1 sh tests/browser/run-editorial-journey.sh
    sh tests/browser/run-record-workflow-journey.sh ;;
  record-keeping)
    CMS_TEST_RECORD_KEEPING_ONLY=1 sh tests/browser/run-editorial-journey.sh
    CMS_TEST_SINGLE_LINE_RICH_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  exact-preview)
    CMS_TEST_EDITOR_EXACT_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_EDITOR_EXACT_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  media)
    CMS_TEST_RESPONSIVE_IMAGES_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_VIDEO_LIMITS_ONLY=1 sh tests/browser/run-editorial-journey.sh
    CMS_TEST_VIMEO_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  portability)
    CMS_TEST_RESTORE_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_IMPORT_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_TRANSFER_ONLY=1 sh tests/php/run-mariadb-integration.sh ;;
  database)
    CMS_TEST_UPGRADE_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_GROWTH_ONLY=1 sh tests/php/run-mariadb-integration.sh ;;
  publication)
    CMS_TEST_PUBLICATION_ONLY=1 sh tests/php/run-mariadb-integration.sh
    CMS_TEST_SCHEDULES_ONLY=1 sh tests/browser/run-editorial-journey.sh
    CMS_TEST_PUBLISHED_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  blocks)
    CMS_TEST_BLOCKS_ONLY=1 sh tests/browser/run-editorial-journey.sh
    CMS_TEST_COMPOSITION_ONLY=1 sh tests/browser/run-editorial-journey.sh ;;
  public-site)
    work=$(mktemp -d "${TMPDIR:-/tmp}/portable-cms-ci-site.XXXXXX")
    trap 'rm -rf "$work"' EXIT INT TERM
    php tools/development/build-profile-site.php --output="$work/site"
    node tools/audit-public-site.mjs "$work/site" --report="$work/report.json" --template=page:home --template=record:page:projects --template=record:page:people --runs=1 --concurrency=1
    node --input-type=module - "$work/report.json" <<'JS'
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const report = JSON.parse(readFileSync(process.argv[2]));
assert.deepEqual(report.templates.map(t => t.id).sort(), ['page:home', 'record:page:people', 'record:page:projects']);
JS
    ;;
  *) printf 'Unknown CI check lane.\n' >&2; exit 1 ;;
esac
