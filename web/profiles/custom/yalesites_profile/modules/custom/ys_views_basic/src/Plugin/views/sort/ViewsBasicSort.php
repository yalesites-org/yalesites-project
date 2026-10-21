<?php

namespace Drupal\ys_views_basic\Plugin\views\sort;

use Drupal\views\Attribute\ViewsSort;
use Drupal\views\Plugin\views\sort\SortPluginBase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Sort that receives JSON overrides from a Views Basic field.
 *
 * @ingroup views_sort_handlers
 */
#[ViewsSort('views_basic_sort')]
class ViewsBasicSort extends SortPluginBase {

  /**
   * Add this sort to the query.
   */
  public function query() {
    $this->ensureMyTable();

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;

    // Split out the field and the sort direction.
    if (isset($this->view->args[ViewsBasicManager::viewArgumentIndex('sort')])) {
      $sortBy = $this->view->args[ViewsBasicManager::viewArgumentIndex('sort')];
      // The value becomes an ORDER BY expression below, and on the views/ajax
      // path it comes from the browser. Anything that is not a sort a listing
      // offers is ignored, leaving the view's default order.
      if (ViewsBasicManager::isAllowedSort($sortBy)) {
        $sortQueryOptions = explode(":", $sortBy);
        if (str_starts_with($sortQueryOptions[0], 'field')) {
          $lookupTable = $query->addTable('node__' . $sortQueryOptions[0]);
          $field = "$lookupTable.$sortQueryOptions[0]_value";
        }
        else {
          $field = $sortQueryOptions[0];
        }

        $query->addOrderBy(NULL, "node_field_data.sticky", 'DESC', 'views_basic_sort');
        $query->addOrderBy(NULL, "{$field}", $sortQueryOptions[1], 'views_basic_sort');
        // Stable tiebreaker: when many rows share the same sort value (common
        // with date fields stored only as month/year), MySQL returns rows in
        // an unspecified order, causing results to shuffle on every reload.
        // Sorting by nid in the same direction produces a deterministic order.
        $query->addOrderBy(NULL, 'node_field_data.nid', $sortQueryOptions[1], 'views_basic_sort');
      }
    }
  }

}
