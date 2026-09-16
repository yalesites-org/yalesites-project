# YaleSites Taxonomy Manager

Additions to the contrib Taxonomy Manager module for YaleSites. Adds an
"add terms to nodes" bulk operation to the Taxonomy Manager UI, letting editors
assign a taxonomy term to many nodes at once.

## Running tests

This module has PHPUnit tests under `tests/src/`. Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_taxonomy_manager/tests"
```

Add `--testdox` for readable output.
