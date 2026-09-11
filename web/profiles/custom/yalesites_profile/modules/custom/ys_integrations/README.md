# ys_integrations Module

## Overview
The `ys_integrations` module provides tools for managing and configuring third-party integrations in Drupal. It includes a centralized dashboard for site and platform administrators to oversee and control integration settings.

## Installation
1. Download the module and place it in the `/modules/custom` directory of your Drupal installation.
2. Enable the module using Drush or through the Drupal admin interface:
   - Using Drush: `drush en ys_integrations`
   - Through the admin interface: Navigate to Extend and find `ys_integrations` in the list.

## Contributing
If you wish to contribute to the `ys_integrations` module, please fork the repository and submit a pull request with your changes.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/` and `Kernel/`), plus a test-only helper module in `tests/modules/`. Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_integrations/tests"
```

Add `--testdox` for readable output. Unit-only tests (no database) can also be run with the shorthand `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_integrations/tests`.
