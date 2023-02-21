<?php

namespace Drupal\ys_views_basic\Plugin\views\sort;

use Drupal\views\Plugin\views\sort\SortPluginBase;

/**
 * Sort that receives JSON overrides from a Views Basic field.
 *
 * @ingroup views_sort_handlers
 *
 * @ViewsSort("views_basic_sort")
 */
class ViewsBasicSort extends SortPluginBase {

  /**
   * Add this sort to the query.
   */
  public function query() {
    $this->ensureMyTable();

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;

    $sortBy = $this->view->args[1];

    // Split out the field and the sort direction.
    $sortQueryOptions = explode(":", $sortBy);
    if (str_starts_with($sortQueryOptions[0], 'field')) {
      $lookupTable = $query->addTable('node__' . $sortQueryOptions[0]);
      $field = "$lookupTable.$sortQueryOptions[0]_value";
    }
    else {
      $field = $sortQueryOptions[0];
    }

    $query->addOrderBy(NULL, "{$field}", $sortQueryOptions[1], 'views_basic_sort');
  }

}
