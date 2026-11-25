<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Views;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Excludes taxonomy terms by ID.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('exclude_taxonomy_terms')]
class ExcludeTaxonomyTerms extends FilterPluginBase {

  /**
   * Add this filter to the query.
   */
  public function query() {

    $terms_index = ViewsBasicManager::viewArgumentIndex('terms_exclude');
    if (!isset($this->view->args[$terms_index])) {
      return;
    }
    else {
      $excludedTerms = $this->view->args[$terms_index];
      // A "," joins terms with All (hide only nodes carrying every term) and
      // a "+" with Any (hide nodes carrying at least one). One term is the
      // same either way.
      $hideOnAll = str_contains($excludedTerms, ',');
      switch ($excludedTerms) {
        case str_contains($excludedTerms, ','):
          $excludedTermsArray = explode(',', $excludedTerms);
          break;

        case str_contains($excludedTerms, '+'):
          $excludedTermsArray = explode('+', $excludedTerms);
          break;

        default:
          $excludedTermsArray[0] = $excludedTerms;
          break;
      }

      if ($hideOnAll) {
        $this->excludeNodesWithAllTerms($excludedTermsArray);
        return;
      }

      foreach ($excludedTermsArray as $term) {
        $joinSearch[] = [
          'field' => 'tid',
          'value' => $term,
        ];
      }

      // Ensure the main table for this handler is in the query.
      $this->ensureMyTable();
      /** @var \Drupal\views\Plugin\views\query\Sql $query */
      $query = $this->query;

      // @see https://api.drupal.org/api/drupal/core%21modules%21views%21src%21Plugin%21views%21join%21JoinPluginBase.php/group/views_join_handlers/8.2.x
      // @see https://api.drupal.org/api/drupal/core%21modules%21views%21src%21Plugin%21views%21query%21Sql.php/function/Sql%3A%3AaddTable/9
      // Create join with extra to search for each term in the taxonomy index.
      $configuration = [
        'table' => 'taxonomy_index',
        'field' => 'nid',
        'left_table' => 'node_field_data',
        'left_formula' => 'node_field_data.nid',
        'operator' => '=',
        'extra' => $joinSearch,
      ];
      $join = Views::pluginManager('join')
        ->createInstance('standard', $configuration);

      // Change operator of the search based on selection in views tool.
      $join->extraOperator = 'OR';

      // Add lookup table with new join.
      $lookupTable = $query->addTable('taxonomy_index', NULL, $join);

      $field = "$lookupTable.tid";
      $operator = 'IS NULL';

      // Add the where clause.
      $query->addWhereExpression(0, "$field $operator");
    }
  }

  /**
   * Hides nodes that carry every one of the given terms.
   *
   * @param array $terms
   *   The term IDs to hide on.
   */
  protected function excludeNodesWithAllTerms(array $terms): void {
    $tids = array_values(array_unique(array_map('intval', $terms)));
    if (!$tids) {
      return;
    }
    $this->ensureMyTable();
    // Term IDs are cast to integers above, so they are safe to inline.
    $list = implode(',', $tids);
    $count = count($tids);
    $this->query->addWhereExpression(0, "node_field_data.nid NOT IN (SELECT ti.nid FROM {taxonomy_index} ti WHERE ti.tid IN ($list) GROUP BY ti.nid HAVING COUNT(DISTINCT ti.tid) = $count)");
  }

}
