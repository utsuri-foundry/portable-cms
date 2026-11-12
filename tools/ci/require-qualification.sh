#!/bin/sh
set -eu
test "${PLAN_RESULT:-}" = success
test "${QUALIFICATION_RESULT:-}" = success
