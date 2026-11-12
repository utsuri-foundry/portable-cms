set shell := ["sh", "-eu", "-c"]

check:
    tools/check-repository.sh

check-fast:
    tools/check-fast.sh

check-architecture:
    php tools/check-architecture.php
    php tests/php/architecture-gate-test.php

check-javascript:
    biome check engine/src/browser engine/src/cms/conventional/client profiles tools tests/unit tests/browser tests/public-site

check-php:
    php tools/check-quality-policy.php
    php tests/php/quality-policy-test.php
    php-cs-fixer check --config=tools/quality/php-cs-fixer.php --diff
    phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G

format:
    biome check --write engine/src/browser engine/src/cms/conventional/client profiles tests/unit tests/browser
    php-cs-fixer fix --config=tools/quality/php-cs-fixer.php

test:
    node --test tests/unit/*.test.js
    php tests/php/run.php

test-bundle:
    sh tests/php/check-conventional-bundle.sh

test-mariadb:
    sh tests/php/run-mariadb-integration.sh

bundle output private_root:
    php tools/build-conventional-cms-bundle.php --output={{output}} --private-root={{private_root}}

build-reference output="build/reference-studio":
    php profiles/reference-studio/build.php --output={{output}}

serve-reference output="build/reference-studio":
    php -S 127.0.0.1:4173 -t {{output}}

serve-reference-cms:
    sh tools/serve-reference-cms.sh

# Serve any verified profile package, such as a private fidelity study, without seed content.
serve-profile-cms profile_root port="4182":
    PROFILE_ROOT={{profile_root}} PORT={{port}} sh tools/serve-profile-cms.sh

reset-reference-cms:
    sh tools/reset-reference-cms.sh

# Audit a compiled static artifact or exact preview against the public site quality bar.
audit-public-site artifact:
    node tools/audit-public-site.mjs {{artifact}}

# Build Reference Studio, audit it, and prove the audit rejects a degraded page.
check-public-site:
    sh tests/public-site/run-public-site-audit.sh
