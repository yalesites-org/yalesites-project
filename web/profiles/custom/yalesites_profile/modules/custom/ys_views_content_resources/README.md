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

## Exposed taxonomy filter options

`ExposedTaxonomyFilterOptions` (service
`ys_views_content_resources.exposed_taxonomy_filter_options`) constrains what
an exposed `taxonomy_index_tid` filter offers. It is not tied to the resources
view and can be used by any module that assembles a Views display's `filters`
option from stored parameters, such as `ys_views_basic`.

Two constraints, alone or together:

- **Parent term** - only that term's descendants are offered (the existing
  "Filter by parent term" behaviour).
- **Excluded terms** - any term the editor used to *exclude content* is removed
  from the dropdown. A visitor choosing such a term would always get zero
  results, so it is never offered.

The vocabulary is read from the filter's own `vid` setting, so there is no
filter-to-vocabulary map to maintain. Excluded ids from other vocabularies
(for example tags) are ignored. When nothing constrains a filter it is left
untouched, so existing blocks keep offering the whole vocabulary.

```php
$filters = $view->getDisplay()->getOption('filters');
$excluded = ExposedTaxonomyFilterOptions::normalizeTermIds($params['filters']['terms_exclude'] ?? []);

// Category: parent term + exclusions.
$this->exposedTaxonomyFilterOptions->apply($filters, 'field_category_target_id', $excluded, $params['category_included_terms'] ?? NULL);

// Any other taxonomy filter: exclusions only.
$this->exposedTaxonomyFilterOptions->apply($filters, 'field_audience_target_id', $excluded);

$view->getDisplay()->setOption('filters', $filters);
```

In this module every exposed taxonomy filter (category, custom vocabulary,
audience, academic year, discipline, areas of study, geographic areas) goes
through `apply()` in `ViewsContentResourcesManager::setupView()`.
