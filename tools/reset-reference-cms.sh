#!/bin/sh

set -eu

repository=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
state="$repository/.local/reference-cms"
expected="$repository/.local/reference-cms"

if [ "$state" != "$expected" ] || [ -L "$state" ]; then
  printf 'Refusing to reset an unexpected sandbox path.\n' >&2
  exit 1
fi
if [ -f "$state/mariadb.pid" ]; then
  pid=$(sed -n '1p' "$state/mariadb.pid")
  case "$pid" in
    ''|*[!0-9]*) ;;
    *)
      if kill -0 "$pid" 2>/dev/null; then
        printf 'Stop the local CMS before resetting it.\n' >&2
        exit 1
      fi
      ;;
  esac
fi
if [ ! -e "$state" ]; then
  printf 'The local Reference Studio CMS is already reset.\n'
  exit 0
fi

rm -rf -- "$state"
printf 'Removed the local Reference Studio CMS state. The next start will restore the seed.\n'
