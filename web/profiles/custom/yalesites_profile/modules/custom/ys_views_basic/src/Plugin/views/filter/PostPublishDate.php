<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Excludes posts whose publish date has not yet been reached.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("post_publish_date")
 */
class PostPublishDate extends FilterPluginBase {

  /**
   * Add this filter to the query.
   */
  public function query() {
    // Only gate post listings. Pages and profiles share this scaffold view but
    // have no publish date, and events use a separate scaffold, so leave any
    // non-post listing untouched.
    if (($this->view->args[0] ?? NULL) !== 'post') {
      return;
    }

    // Ensure the base table for this handler is in the query.
    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;

    $lookupTable = $query->addTable('node__field_publish_date');
    $field = "$lookupTable.field_publish_date_value";

    // field_publish_date is a date-only field stored as an ISO 'Y-m-d' string,
    // so compare lexicographically against today. Posts dated today are kept
    // (on or past the publish date); only strictly future posts are excluded.
    $query->addWhere($this->options['group'], $field, date('Y-m-d'), '<=');
  }

}
