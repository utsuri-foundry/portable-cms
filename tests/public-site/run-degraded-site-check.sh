#!/bin/sh

set -eu

repository=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repository"
work=$(mktemp -d "${TMPDIR:-/tmp}/portable-cms-degraded-check.XXXXXX")
trap 'rm -rf "$work"' EXIT INT TERM
php tools/development/build-profile-site.php --output="$work/reference-studio"
node tests/public-site/degraded-site-test.mjs "$work/reference-studio"
