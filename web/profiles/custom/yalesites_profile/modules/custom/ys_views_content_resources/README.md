# YaleSites Views Content Resources

## Description
Views Content Resources lets content editors insert a customizable "resource"
view (journal articles, publications, and similar content) through a
no-code field widget, without needing to build a Drupal View by hand. It is a
sibling of the `ys_views_basic` module, built on the same field
type/widget/formatter pattern but scoped to the `resource` content type and
its specific filters (category, custom vocabulary, audience, academic year,
discipline, areas of study, geographic areas, and publish year).

## Features
- **Field Plugin**: View configuration is stored as a serialized JSON object
  in a custom field, so new options can be added without changing the
  underlying schema.
- **Field Widget**: A guided, no-code form for choosing view mode, filters,
  sort order, pinning, and pagination.
- **Field Formatter**: Renders the configured resources view, plus a
  settings-preview formatter used in the Layout Builder editing UI.
- **`resource_year_filter` Views plugin**: An exposed filter listing only the
  years that actually appear on a published resource's publish date.

## Running tests

This module has PHPUnit tests under `tests/src/` covering
ViewsContentResourcesManager, the ResourceYearFilter Views plugin, and the
field type/widget/formatter plugins. Run them from the project root on the
local Lando environment, passing the module's `tests` path so PHPUnit only
discovers this module's tests (not Drupal core/contrib):

```bash
lando ssh -c "env SIMPLETEST_DB=mysql://pantheon:pantheon@database/pantheon \
  php /app/vendor/bin/phpunit -c /app/phpunit.xml \
  /app/web/profiles/custom/yalesites_profile/modules/custom/ys_views_content_resources/tests"
```

Add `--testdox` for readable output.
