#!/usr/bin/env bash
#
# Run the PHPUnit Unit suite for every custom module, one PHPUnit process per
# module. Drupal tests share container state when run in one large process, and
# PHPUnit honors only the first path argument, so each module gets its own run.
# Needs no database or environment variables. Works the same in Lando, DDEV and
# GitHub Actions.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MODULES_DIR="$ROOT/web/profiles/custom/yalesites_profile/modules/custom"
cd "$ROOT" || exit 1

failed=()
for dir in "$MODULES_DIR"/*/tests/src/Unit; do
  [ -d "$dir" ] || continue
  module="$(basename "$(dirname "$(dirname "$(dirname "$dir")")")")"
  echo "== $module"
  if ! "$ROOT/vendor/bin/phpunit" -c "$ROOT/phpunit.xml" --no-coverage "$dir"; then
    failed+=("$module")
  fi
done

if [ "${#failed[@]}" -gt 0 ]; then
  echo "Unit tests failed in: ${failed[*]}" >&2
  exit 1
fi
