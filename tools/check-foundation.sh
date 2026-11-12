#!/bin/sh

set -eu

repository_root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$repository_root"

node tools/publication/check.mjs
php tools/check-engine-release.php
php tests/php/engine-release-gate-test.php
php tools/check-architecture.php
php tools/contracts/check-profile-groups-schema.php
php tools/contracts/check-design-schema.php
php tools/contracts/check-website-publication-schema.php
php tools/contracts/check-export-package-schema.php
php tools/contracts/check-content-feed-schema.php
php tools/contracts/check-vimeo-video-schema.php
php tests/php/architecture-gate-test.php
composer validate --strict --no-check-publish
composer audit --locked
php tools/check-quality-policy.php
php tests/php/quality-policy-test.php
biome check engine/src/browser engine/src/cms/conventional/client profiles tools tests/unit tests/browser tests/public-site
php-cs-fixer check --config=tools/quality/php-cs-fixer.php --diff
phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
node --test tests/unit/*.test.js
php tools/check-php-templates.php
php tests/php/run.php
sh tests/php/check-conventional-bundle.sh
