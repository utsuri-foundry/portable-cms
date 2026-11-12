#!/bin/sh

set -eu

# Every resource belongs to this ephemeral test run; no persistent sandbox is touched.
state=$(mktemp -d)
socket="$state/mariadb.sock"
server_pid=
browser_pid=
cleanup() {
  if [ -n "$server_pid" ]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  if [ -n "$browser_pid" ]; then kill "$browser_pid" 2>/dev/null || true; wait "$browser_pid" 2>/dev/null || true; fi
  if [ -f "$state/mariadb.pid" ]; then
    mariadb-admin --no-defaults --socket="$socket" --user=root shutdown >/dev/null 2>&1 || true
  fi
  rm -rf -- "$state"
}
trap cleanup EXIT HUP INT TERM

command -v chromium >/dev/null
mariadb-install-db --no-defaults --auth-root-authentication-method=normal --skip-test-db --datadir="$state/database" >"$state/install.log" 2>&1
mariadbd --no-defaults --datadir="$state/database" --socket="$socket" --pid-file="$state/mariadb.pid" --log-error="$state/mariadb.log" --skip-networking &
attempt=0
until mariadb-admin --no-defaults --socket="$socket" --user=root ping >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 100 ]; then cat "$state/mariadb.log" >&2; exit 1; fi
  sleep 0.1
done
mariadb --no-defaults --socket="$socket" --user=root <<'SQL'
CREATE DATABASE portable_reference CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'portable'@'localhost' IDENTIFIED BY 'portable-local-database';
GRANT ALL PRIVILEGES ON portable_reference.* TO 'portable'@'localhost';
SQL
port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $a = stream_socket_get_name($s, false); fclose($s); echo substr($a, strrpos($a, ":") + 1);')
debug_port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $a = stream_socket_get_name($s, false); fclose($s); echo substr($a, strrpos($a, ":") + 1);')
php tools/development/prepare-reference-cms.php --state="$state" --socket="$socket" --port="$port" --seed=none
CMS_CONFIG="$state/private/cms.json" CMS_PROFILE_ROOT="$PWD/profiles/reference-studio" \
  node tests/browser/local-php-server.mjs "localhost:$port" tools/development/reference-cms-router.php >"$state/server.log" 2>&1 &
server_pid=$!
chromium --headless --disable-gpu --remote-debugging-address=127.0.0.1 --remote-debugging-port="$debug_port" --user-data-dir="$state/browser" about:blank >"$state/browser.log" 2>&1 &
browser_pid=$!
export CMS_BROWSER_ORIGIN="http://localhost:$port"
export CMS_BROWSER_DEBUG_URL="http://127.0.0.1:$debug_port"
export CMS_BROWSER_WORKER_CONFIG="$state/private/cms.json"
if ! node --input-type=module -e '
for (const url of [process.env.CMS_BROWSER_ORIGIN + "/login", process.env.CMS_BROWSER_DEBUG_URL + "/json/version"]) {
  let ready = false;
  for (let attempt = 0; attempt < 100; attempt++) {
    try { const response = await fetch(url); if (response.ok) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  if (!ready) throw new Error("Empty CMS did not become ready");
}'; then cat "$state/server.log" "$state/browser.log" >&2; exit 1; fi
if [ "${CMS_TEST_ACCOUNTS_ONLY:-0}" != "1" ] && ! node tests/browser/record-workflow-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/account-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
