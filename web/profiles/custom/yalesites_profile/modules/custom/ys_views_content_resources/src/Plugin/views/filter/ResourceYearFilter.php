<?php

namespace Drupal\ys_views_content_resources\Plugin\views\filter;

use Drupal\ys_views_basic\Plugin\views\filter\ResourceYearFilter as ViewsBasicResourceYearFilter;

/**
 * Filter resources by year.
 *
 * The "resource_year_filter" plugin now lives in ys_views_basic (#1723). This
 * class carries no plugin annotation, so the id has a single provider while
 * both modules are installed; it only keeps the old class name resolving
 * until this module is removed.
 */
class ResourceYearFilter extends ViewsBasicResourceYearFilter {

}
