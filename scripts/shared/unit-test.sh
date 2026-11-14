#!/usr/bin/env bash
#
# Run the PHPUnit Unit suite for every custom module, one PHPUnit process per
# module (including nested submodules). Drupal tests share container state when run in one large process, and
# PHPUnit honors only the first path argument, so each module gets its own run.
# Needs no database or environment variables. Works the same in Lando, DDEV and
# GitHub Actions.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MODULES_DIR="$ROOT/web/profiles/custom/yalesites_profile/modules/custom"
cd "$ROOT" || exit 1

# Deprecation notices from contrib code are reported but must not fail the build.
export SYMFONY_DEPRECATIONS_HELPER="${SYMFONY_DEPRECATIONS_HELPER:-weak}"

failed=()
ran=0
for dir in "$MODULES_DIR"/*/tests/src/Unit "$MODULES_DIR"/*/modules/*/tests/src/Unit; do
  [ -d "$dir" ] || continue
  module="$(basename "$(dirname "$(dirname "$(dirname "$dir")")")")"
  echo "== $module"
  ran=$((ran + 1))
  if ! "$ROOT/vendor/bin/phpunit" -c "$ROOT/phpunit.xml" --no-coverage "$dir"; then
    failed+=("$module")
  fi
done

# A run that finds no test folders would otherwise pass with nothing run.
if [ "$ran" -eq 0 ]; then
  echo "No Unit test folders found under $MODULES_DIR" >&2
  exit 1
fi

if [ "${#failed[@]}" -gt 0 ]; then
  echo "Unit tests failed in: ${failed[*]}" >&2
  exit 1
fi
