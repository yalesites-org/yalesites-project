<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Filter events by date.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("event_time_period")
 */
class EventTimePeriod extends FilterPluginBase {

  /**
   * Add this filter to the query.
   */
  public function query() {

    $period_index = ViewsBasicManager::viewArgumentIndex('event_time_period');
    if (!isset($this->view->args[$period_index])) {
      return;
    }
    else {

      switch ($this->view->args[$period_index]) {
        case 'future':
          $operator = '>=';
          break;

        case 'past':
          $operator = '<';
          break;

        default:
          return;
      }

      // Ensure the main table for this handler is in the query.
      $this->ensureMyTable();
      /** @var \Drupal\views\Plugin\views\query\Sql $query */
      $query = $this->query;

      $lookupTable = $query->addTable('node__field_event_date');
      $field = "$lookupTable.field_event_date_end_value";

      $query->addWhere($this->options['group'], $field, time(), $operator);

    }
  }

}
