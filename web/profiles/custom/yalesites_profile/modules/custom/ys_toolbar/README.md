# YaleSites Toolbar

## Description
The YaleSites Toolbar module enhances the Drupal Admin toolbar, tailored to empower site owners in managing their websites effectively. This module integrates with and builds upon the Toolbar and Gin Toolbar modules, customizing the authoring experience for the YaleSites community.

## Features
- **Enhanced Local Task Links**: The module introduces a collection of new local task links, offering quick access to essential functions such as editing, publishing, unpublishing, and changing the layout of a node. These links provide a consistent and efficient toolset for site authors, simplifying common tasks and streamlining content management.
- **Labels and Icons**: To enhance usability and user understanding, several toolbar items receive refreshed names and icons. This thoughtful approach establishes a standardized naming convention that aligns with Yale's training practices, ensuring that all authors have a clear grasp of content management intricacies.

## Running tests

This module has PHPUnit tests under `tests/src/` (`Unit/` and `Kernel/`). Run them from the project root on the local Lando environment, passing the module's `tests` path so PHPUnit only discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_toolbar/tests"
```

Add `--testdox` for readable output. Unit-only tests (no database) can also be run with the shorthand `lando phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_toolbar/tests`.
