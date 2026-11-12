#!/bin/sh

set -eu

# Every resource belongs to this ephemeral test run; no persistent sandbox is touched.
state=$(mktemp -d)
socket="$state/mariadb.sock"
server_pid=
browser_pid=
public_pid=
cleanup() {
  if [ "$?" -ne 0 ] && [ -f "$state/private/errors.log" ]; then
    tail -n 8 "$state/private/errors.log" >&2
  fi
  if [ -n "$server_pid" ]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  if [ -n "$browser_pid" ]; then kill "$browser_pid" 2>/dev/null || true; wait "$browser_pid" 2>/dev/null || true; fi
  if [ -n "$public_pid" ]; then kill "$public_pid" 2>/dev/null || true; wait "$public_pid" 2>/dev/null || true; fi
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
export CMS_LOCAL_FONT_SANITIZER="$(command -v ots-sanitize)"
export CMS_LOCAL_FONT_COMPRESSOR="$(command -v woff2_compress)"
php tools/development/prepare-reference-cms.php --state="$state" --socket="$socket" --port="$port"
php tools/conventional-media-worker.php --config="$state/private/cms.json" --max-jobs=10 --max-seconds=60 --worker-id=browser-fixture
php profiles/reference-studio/build.php --output="$state/static"
profile_root="$PWD/profiles/reference-studio"
if [ "${CMS_TEST_VIDEO_LIMITS_ONLY:-0}" = "1" ]; then
  php tests/php/prepare-video-limits-browser.php "$state"
  profile_root="$state/video-profile"
  export CMS_BROWSER_VIDEO_PROBES="$state/video-probes"
fi
if [ "${CMS_TEST_SINGLE_LINE_RICH_ONLY:-0}" = "1" ]; then
  php tests/php/prepare-single-line-rich-text-browser.php "$state"
  profile_root="$state/single-line-profile"
fi
if [ "${CMS_TEST_VIMEO_ONLY:-0}" = "1" ]; then
  php tests/php/prepare-vimeo-browser.php "$state"
  profile_root="$state/vimeo-profile"
  export CMS_BROWSER_VIMEO_STATE="$state"
fi
public_port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $a = stream_socket_get_name($s, false); fclose($s); echo substr($a, strrpos($a, ":") + 1);')
public_router=
if [ "${CMS_TEST_VIMEO_ONLY:-0}" = "1" ]; then public_router="$PWD/tests/browser/vimeo-public-router.php"; fi
node tests/browser/local-php-server.mjs "localhost:$public_port" -t "$state/static" ${public_router:+"$public_router"} >"$state/public.log" 2>&1 &
public_pid=$!
CMS_CONFIG="$state/private/cms.json" CMS_PROFILE_ROOT="$profile_root" \
  node tests/browser/local-php-server.mjs "localhost:$port" tools/development/reference-cms-router.php >"$state/server.log" 2>&1 &
server_pid=$!
chromium --headless --disable-gpu --remote-debugging-address=127.0.0.1 --remote-debugging-port="$debug_port" --user-data-dir="$state/browser" about:blank >"$state/browser.log" 2>&1 &
browser_pid=$!
export CMS_BROWSER_ORIGIN="http://localhost:$port"
export CMS_BROWSER_DEBUG_URL="http://127.0.0.1:$debug_port"
export CMS_BROWSER_PUBLIC_ORIGIN="http://localhost:$public_port"
export CMS_BROWSER_SCREENSHOT="${CMS_BROWSER_SCREENSHOT:-$state/editorial.png}"
export CMS_BROWSER_WORKER_CONFIG="$state/private/cms.json"
if ! node --input-type=module -e '
for (const url of [process.env.CMS_BROWSER_ORIGIN + "/login", process.env.CMS_BROWSER_DEBUG_URL + "/json/version"]) {
  let ready = false;
  for (let attempt = 0; attempt < 100; attempt++) {
    try { const response = await fetch(url); if (response.ok) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  if (!ready) throw new Error("Browser journey service did not become ready");
}'; then cat "$state/server.log" "$state/browser.log" >&2; exit 1; fi
if [ -n "${CMS_TEST_CI_BROWSER_CASE:-}" ]; then
  if ! node tools/ci/browser-cases.mjs execute "$CMS_TEST_CI_BROWSER_CASE"; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_EDITOR_START_ONLY:-0}" = "1" ]; then
  node tests/browser/reload-readiness-journey.mjs
  if ! node tests/browser/editor-start-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_RECORD_KEEPING_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/record-keeping-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_EDITOR_EXACT_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/editor-exact-preview-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi

if [ "${CMS_TEST_PUBLISHED_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/published-record-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_VIMEO_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/vimeo-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_SINGLE_LINE_RICH_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/single-line-rich-text-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_VIDEO_LIMITS_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/video-field-limits-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_BLOCKS_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/blocks-editor-journey.mjs; then
    cat "$state/server.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_INSTALLATION_ONLY:-0}" = "1" ]; then
  node tests/browser/installation-brand-journey.mjs
  exit 0
fi
if [ "${CMS_TEST_LOGO_ONLY:-0}" = "1" ]; then
  node tests/browser/administration-logo-journey.mjs
  exit 0
fi
if [ "${CMS_TEST_HOLDING_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/holding-page-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_DESCRIPTIONS_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/media-descriptions-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_SCHEDULES_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/release-schedule-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_FOCAL_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/focal-point-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_FIELD_UPLOADS_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/edited-field-upload-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_COMPOSITION_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/composition-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_LIVE_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/live-preview-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
# Prove legacy JSON export before later journeys author v2-only linked history.
if [ "${CMS_TEST_DESIGN_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/design-registry-journey.mjs; then
    cat "$state/server.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_VIDEO_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/video-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if [ "${CMS_TEST_SEARCH_SHARING_ONLY:-0}" = "1" ]; then
  if ! node tests/browser/search-sharing-journey.mjs; then
    cat "$state/server.log" "$state/browser.log" >&2
    exit 1
  fi
  exit 0
fi
if ! node tests/browser/reload-readiness-journey.mjs; then
  cat "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/installation-brand-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/design-registry-journey.mjs; then
  cat "$state/server.log" >&2
  exit 1
fi
if ! node tests/browser/preview-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/search-sharing-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/release-schedule-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/preview-cleanup-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/structured-data-journey.mjs; then
  cat "$state/public.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/composition-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" "$state/public.log" >&2
  exit 1
fi
if ! node tests/browser/live-preview-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/blocks-editor-journey.mjs; then
  cat "$state/server.log" >&2
  exit 1
fi
if ! node tests/browser/editorial-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/editorial-controls-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/edited-field-upload-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/media-text-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/media-descriptions-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/focal-point-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/video-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/web-address-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/site-languages-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/site-wording-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/added-languages-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/mixed-reference-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/interface-language-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
php tests/php/ai-mariadb-integration.php "$state/private/cms.json"
export CMS_BROWSER_AI_CONFIG="$state/private/cms.json"
if ! node tests/browser/ai-assistance-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/holding-page-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
if ! node tests/browser/administration-logo-journey.mjs; then
  cat "$state/server.log" "$state/browser.log" >&2
  exit 1
fi
