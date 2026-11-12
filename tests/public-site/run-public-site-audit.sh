#!/bin/sh

set -eu

# Builds Reference Studio's static artifact, audits it against the public site bar, and
# proves the audit rejects a deliberately degraded page.
repository=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repository"
for tool in httpd chromium lighthouse vnu node php; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    printf 'Missing required tool for the public site audit: %s\n' "$tool" >&2
    exit 1
  fi
done
work=$(mktemp -d "${TMPDIR:-/tmp}/portable-cms-public-site-audit.XXXXXX")
trap 'rm -rf "$work"' EXIT INT TERM
php tools/development/build-profile-site.php --output="$work/reference-studio"
node tools/audit-public-site.mjs "$work/reference-studio" --report="$work/report.json"
# The same site with one language turned off and another primary language, as chosen in Settings,
# served at the root with the other language under its prefix.
php tools/development/build-profile-site.php --output="$work/reference-studio-languages" --languages=ca,es --primary=es --primary-at-root
node tools/audit-public-site.mjs "$work/reference-studio-languages" --report="$work/languages-report.json" --runs=1
node tests/public-site/degraded-site-test.mjs "$work/reference-studio"
php tests/public-site/build-holding.php "$work/holding"
node tools/audit-public-site.mjs "$work/holding" --report="$work/holding-report.json"
node tests/public-site/speculation-rules-test.mjs "$work/reference-studio" "$work/holding"
php tests/public-site/build-font-families.php "$work/font-families"
node tools/audit-public-site.mjs "$work/font-families" --report="$work/font-families-report.json" --runs=1
node tests/public-site/font-families-test.mjs "$work/reference-studio" "$work/font-families"
