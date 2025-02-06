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

    // Split out the field and the sort direction.
    if (isset($this->view->args[3])) {
      $sortBy = $this->view->args[3];
      if (str_contains($sortBy, ':')) {
        $sortQueryOptions = explode(":", $sortBy);
        if (str_starts_with($sortQueryOptions[0], 'field')) {
          $lookupTable = $query->addTable('node__' . $sortQueryOptions[0]);
          $field = "$lookupTable.$sortQueryOptions[0]_value";
        }
        else {
          $field = $sortQueryOptions[0];
        }

        if ($this->shouldFeaturePinToTop()) {
          $query->addOrderBy(NULL, "node_field_data.sticky", 'DESC', 'views_basic_sort');
        }
        $query->addOrderBy(NULL, "{$field}", $sortQueryOptions[1], 'views_basic_sort');
      }
    }
  }

  /**
   * Determines if the view should feature a pin to top.
   */
  protected function shouldFeaturePinToTop() {
    $pinOptions = $this->getPinOptions($this->view->args[10]);
    if ($pinOptions && $pinOptions['pinned_to_top']) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Decodes the pin option parameters.
   *
   * @param string $args
   *   The arguments passed to the view.
   *
   * @return array
   *   The decoded pin options.
   */
  protected function getPinOptions($args) {
    if ($args) {
      return json_decode($args, TRUE);
    }

    return [];
  }

}
