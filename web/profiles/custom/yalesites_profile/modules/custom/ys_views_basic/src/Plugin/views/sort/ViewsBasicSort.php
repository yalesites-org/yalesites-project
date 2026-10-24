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

    if (!isset($this->view->args[3])) {
      return;
    }

    $sort = $this->parseSortArgument($this->view->args[3]);
    if ($sort === NULL) {
      return;
    }
    [$fieldName, $direction] = $sort;

    if (str_starts_with($fieldName, 'field_')) {
      $lookupTable = $query->addTable('node__' . $fieldName);
      $field = "$lookupTable.{$fieldName}_value";
    }
    else {
      $field = "node_field_data.$fieldName";
    }

    $query->addOrderBy(NULL, "node_field_data.sticky", 'DESC', 'views_basic_sort');
    $query->addOrderBy(NULL, $field, $direction, 'views_basic_sort');
    // Stable tiebreaker: when many rows share the same sort value (common
    // with date fields stored only as month/year), MySQL returns rows in
    // an unspecified order, causing results to shuffle on every reload.
    // Sorting by nid in the same direction produces a deterministic order.
    $query->addOrderBy(NULL, 'node_field_data.nid', $direction, 'views_basic_sort');
  }

  /**
   * Splits a "field_name:DIRECTION" argument and checks it against the list.
   *
   * @param mixed $sortBy
   *   The sort argument.
   *
   * @return array|null
   *   [field name, upper-case direction] when the field appears in a
   *   ViewsBasicManager::ALLOWED_ENTITIES sort_by option and the direction is
   *   ASC or DESC; NULL otherwise.
   */
  protected function parseSortArgument($sortBy): ?array {
    if (!is_string($sortBy)) {
      return NULL;
    }
    $parts = explode(':', $sortBy);
    if (count($parts) !== 2) {
      return NULL;
    }
    [$fieldName, $direction] = $parts;
    $direction = strtoupper($direction);
    if (!in_array($direction, ['ASC', 'DESC'], TRUE)) {
      return NULL;
    }
    if (!in_array($fieldName, ViewsBasicManager::getAllowedSortFields(), TRUE)) {
      return NULL;
    }
    return [$fieldName, $direction];
  }

}
