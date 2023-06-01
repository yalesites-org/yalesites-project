<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;

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

    if (!isset($this->view->args[6])) {
      return;
    }
    else {

      switch ($this->view->args[6]) {
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
