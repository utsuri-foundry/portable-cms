#!/bin/sh

set -eu
test "${CI_RUNNER_ENVIRONMENT:-}" = github-hosted
sandbox_target=/usr/local/sbin/portable-cms-chromium-sandbox
sandbox_wrapper=/run/wrappers/bin/__chromium-suid-sandbox
case "${1:-}" in
  prepare)
    sandbox_output="$(nix build --no-link --print-out-paths .#ci-chromium-sandbox)"
    test ! -e "$sandbox_target"
    test ! -L "$sandbox_target"
    test ! -e "$sandbox_wrapper"
    test ! -L "$sandbox_wrapper"
    sudo install -d -o root -g root -m 0755 /run/wrappers/bin
    sudo install -o root -g root -m 4755 "$sandbox_output/bin/__chromium-suid-sandbox" "$sandbox_target"
    # Mark ownership before startup so failure still runs workflow cleanup.
    echo 'installed=true' >> "$GITHUB_OUTPUT"
    sudo ln -s "$sandbox_target" "$sandbox_wrapper"
    test "$(stat -c '%u:%g:%a' "$sandbox_target")" = 0:0:4755
    timeout 20s nix develop -c chromium --headless --disable-gpu --user-data-dir="$RUNNER_TEMP/cms-chromium-sandbox-check" --dump-dom about:blank ;;
  remove) sudo rm -f -- "$sandbox_wrapper" "$sandbox_target" ;;
  *) printf 'Unknown sandbox operation.\n' >&2; exit 1 ;;
esac
