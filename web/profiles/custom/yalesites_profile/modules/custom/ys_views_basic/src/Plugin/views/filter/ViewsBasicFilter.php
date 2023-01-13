<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Filter that receives JSON overrides from a Views Basic field.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("views_basic_filter")
 */
class ViewsBasicFilter extends FilterPluginBase {

  /**
   * Add this filter to the query.
   */
  public function query() {
    // Ensure the main table for this handler is in the query.
    $this->ensureMyTable();

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;

    // Parse content type filters.
    // @todo Add taxonomy term as a filter. Code here: https://gist.github.com/marcb4k/fd4716eb133aa846f90a9e42c80def8c.
    foreach ($this->value['filters']['types'] as $content_type) {
      $query->addWhere($this->options['group'], 'type', $content_type);
    }
  }

}
