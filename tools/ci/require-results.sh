#!/bin/sh

set -eu
test "${PLAN_RESULT:-}" = success
test "${FAST_RESULT:-}" = success
case "${LANES:-}" in
  '[]') test "${FOCUSED_RESULT:-}" = skipped ;;
  '['*) test "${FOCUSED_RESULT:-}" = success ;;
  *) exit 1 ;;
esac
