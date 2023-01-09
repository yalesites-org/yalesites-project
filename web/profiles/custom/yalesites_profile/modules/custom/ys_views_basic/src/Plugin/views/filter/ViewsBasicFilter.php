<?php

/**
* @file
* Definition of Drupal\movie_views\Plugin\views\filter\ExcitedTitleFilter.
*/

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
* Filters a list of node titles that include an exclamation mark.
*
* @ingroup views_filter_handlers
*
* @ViewsFilter("views_basic_filter_type")
*/
class ViewsBasicFilter extends FilterPluginBase {

/**
   * Add this filter to the query.
   */
  public function query() {
    // Ensure the main table for this handler is in the query.
    $this->ensureMyTable();

    // Add a condition where the title contains the exclamation mark.
    //kint($this->query());
    kint(get_class_methods($this->query));
    $this->query->addWhere($this->options['group'], 'type', 'news');
    $tag_table = $this->query->addTable('node__field_tags');
    $this->query->addWhere(0, "$tag_table.field_tags_target_id", 1);
    //$this->query->addWhere($this->options['group'], 'type', '!', 'REGEXP');
  }

 }
