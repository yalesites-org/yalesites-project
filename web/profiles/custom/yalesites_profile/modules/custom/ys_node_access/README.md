# YaleSites Node Access

## Description
The YaleSites Node Access module offers a flexible mechanism that allows site authors to control access to the canonical view of a node exclusively for CAS authenticated users. This feature serves as a valuable tool for selectively restricting access to specific page content. However, it is important to note that this module is not intended to function as a secure repository for sensitive content. Other mechanisms within the platform may still expose assets and metadata associated with these nodes to users who are not authenticated, which means that sensitive content should be handled through alternative, more secure means.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/`, `Kernel/`, and `Functional/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests"
```

Add `--testdox` for readable output. Unit-only tests (no database) can also be run with the shorthand `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_node_access/tests`.
