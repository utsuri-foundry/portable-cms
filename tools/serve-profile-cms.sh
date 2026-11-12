#!/bin/sh

set -eu

# Serves any verified profile package through the local product sandbox.
# PROFILE_ROOT: profile package directory (default: Reference Studio).
# STATE: private sandbox state directory (default: .local/profile-cms/<profile>).
# SEED: reference (Reference Studio only) or none (default).
repository=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
profile_root=$(CDPATH= cd -- "${PROFILE_ROOT:-$repository/profiles/reference-studio}" && pwd)
profile_name=$(basename -- "$profile_root")
state=${STATE:-"$repository/.local/profile-cms/$profile_name"}
seed=${SEED:-none}
if [ ! -f "$profile_root/profile.json" ]; then
  printf 'PROFILE_ROOT must contain a profile.json.\n' >&2
  exit 64
fi
mkdir -p "$state"
state=$(CDPATH= cd -- "$state" && pwd)
database="$state/database"
state_key=$(printf '%s' "$state" | cksum | awk '{print $1}')
socket="${TMPDIR:-/tmp}/portable-cms-$state_key.sock"
pid_file="$state/mariadb.pid"
log="$state/mariadb.log"
lock="$state/start.lock"
port=${PORT:-4181}

case "$port" in
  ''|*[!0-9]*) printf 'PORT must be an integer between 1024 and 65535.\n' >&2; exit 64 ;;
esac
if [ "$port" -lt 1024 ] || [ "$port" -gt 65535 ]; then
  printf 'PORT must be an integer between 1024 and 65535.\n' >&2
  exit 64
fi

mkdir -p "$state"
chmod 700 "$state"
if ! mkdir "$lock" 2>/dev/null; then
  printf 'This CMS sandbox is already starting or running: %s\n' "$state" >&2
  exit 1
fi

database_started=false
worker_pid=
server_pid=
cleaned=false
cleanup() {
  if [ "$cleaned" = true ]; then
    return
  fi
  cleaned=true
  if [ -n "$server_pid" ]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    server_pid=
  fi
  if [ -n "$worker_pid" ]; then
    kill "$worker_pid" 2>/dev/null || true
    wait "$worker_pid" 2>/dev/null || true
    worker_pid=
  fi
  if [ "$database_started" = true ]; then
    mariadb-admin --no-defaults --socket="$socket" --user=root shutdown >/dev/null 2>&1 || true
    database_started=false
  fi
  rmdir "$lock" 2>/dev/null || true
}
stop() {
  cleanup
  exit 0
}
trap cleanup EXIT
trap stop HUP INT TERM

if [ ! -d "$database/mysql" ]; then
  mkdir -p "$database"
  mariadb-install-db \
    --no-defaults \
    --auth-root-authentication-method=normal \
    --skip-test-db \
    --datadir="$database" >/dev/null
fi

mariadbd \
  --no-defaults \
  --datadir="$database" \
  --socket="$socket" \
  --pid-file="$pid_file" \
  --log-error="$log" \
  --skip-networking &
database_started=true

attempt=0
until mariadb-admin --no-defaults --socket="$socket" --user=root ping >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 100 ]; then
    sed -n '1,120p' "$log" >&2
    exit 1
  fi
  sleep 0.1
done

mariadb --no-defaults --socket="$socket" --user=root <<'SQL'
CREATE DATABASE IF NOT EXISTS portable_reference CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'portable'@'localhost' IDENTIFIED BY 'portable-local-database';
GRANT ALL PRIVILEGES ON portable_reference.* TO 'portable'@'localhost';
SQL

export CMS_LOCAL_FONT_SANITIZER="$(command -v ots-sanitize || true)"
export CMS_LOCAL_FONT_COMPRESSOR="$(command -v woff2_compress || true)"
php "$repository/tools/development/prepare-reference-cms.php" \
  --state="$state" \
  --socket="$socket" \
  --port="$port" \
  --profile-root="$profile_root" \
  --seed="$seed"

export CMS_CONFIG="$state/private/cms.json"
export CMS_PROFILE_ROOT="$profile_root"
(
  while :; do
    php "$repository/tools/conventional-media-worker.php" \
      --config="$CMS_CONFIG" \
      --max-jobs=2 \
      --max-seconds=5 \
      --worker-id=local-sandbox >/dev/null 2>&1 || true
    sleep 2
  done
) &
worker_pid=$!

printf '\n%s CMS is ready.\n' "$profile_name"
printf 'URL:      http://localhost:%s/\n' "$port"
printf 'Login:    admin@portable.test\n'
printf 'Password: portable-cms-local\n'
printf 'State:    %s\n' "$state"
printf 'Stop with Ctrl-C. Edits are preserved.\n\n'

php -S "localhost:$port" "$repository/tools/development/reference-cms-router.php" &
server_pid=$!
if wait "$server_pid"; then
  server_pid=
  exit 0
fi
status=$?
server_pid=
exit "$status"
