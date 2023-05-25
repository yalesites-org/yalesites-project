<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Views;

/**
 * Excludes taxonomy terms by ID.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("exclude_taxonomy_terms")
 */
class ExcludeTaxonomyTerms extends FilterPluginBase {

  /**
   * Add this filter to the query.
   */
  public function query() {
    // Ensure the main table for this handler is in the query.
    $this->ensureMyTable();
    $query = $this->query;

    $configuration = array(
      'table' => 'taxonomy_index',
      'field' => 'nid',
      'left_table' => 'node_field_data',
      'left_formula' => 'node_field_data.nid',
      'operator' => '=',
      'extra' => array(
        0 => array(
          'field' => 'tid',
          'value' => '2',
        ),
      ),
    );
    $join = Views::pluginManager('join')
      ->createInstance('standard', $configuration);

    $lookupTable = $query->addTable('taxonomy_index', NULL, $join);

    //   ->condition('taxonomy_index.tid', '3', '=');
    $field = "$lookupTable.tid";
    $operator = 'IS NULL';
    $this->query->addWhereExpression(0, "$field $operator");

    //kint($query->query()->__toString());
  }

 }
