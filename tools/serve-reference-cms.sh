#!/bin/sh

set -eu

# The persistent Reference Studio sandbox: the generic profile sandbox with its development seed.
repository=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PROFILE_ROOT="$repository/profiles/reference-studio" STATE="$repository/.local/reference-cms" SEED=reference \
  exec sh "$repository/tools/serve-profile-cms.sh"
