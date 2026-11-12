#!/bin/sh

set -eu
repository_root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$repository_root"
sh tools/check-foundation.sh
tools/check-extraction-boundary.sh
gitleaks git --log-opts='--all --full-history' --redact=100 --ignore-gitleaks-allow --no-banner .
git diff --check
printf 'Portable CMS fast checks passed; full qualification is separate.\n'
