#!/bin/sh

set -eu

repository_root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$repository_root"

implementation_paths="engine config schemas tests tools"
for forbidden in \
    'wrangler' \
    'workers.dev' \
    'cloudflare' \
    'account_id' \
    'database_id' \
    'bucket_name'; do
    if rg --ignore-case --line-number --glob '!check-extraction-boundary.sh' "$forbidden" $implementation_paths; then
        printf 'Extracted implementation contains forbidden provider term: %s\n' "$forbidden" >&2
        exit 1
    fi
done

if git ls-files -- '.local' '.local/**' | grep -q .; then
    printf 'Machine-local state must not enter the product repository.\n' >&2
    exit 1
fi

printf 'Extraction boundary check passed.\n'
