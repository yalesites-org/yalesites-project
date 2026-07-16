# YaleSites Book

Customizations to Drupal's Book module for YaleSites. Overrides the
`custom_book_block` `book.manager` service with `YsExpandBookManager` so that
CAS-protected (and other access-restricted) published pages remain visible in the
book navigation — flagged for a lock icon — rather than being filtered out, and
invalidates collection-navigation caches on book node changes.

## Running tests

This module has PHPUnit tests under `tests/src/`. Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_book/tests"
```

Add `--testdox` for readable output.
