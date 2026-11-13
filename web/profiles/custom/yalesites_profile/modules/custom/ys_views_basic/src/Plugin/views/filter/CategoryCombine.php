<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\Combine;

/**
 * Keyword search that also matches category term names (#706).
 *
 * Swapped in for the scaffold views' combine filter on post and event
 * listings (see ViewsBasicManager::configureView()). The fields the search
 * scope picks are matched as core's combine filter does; category names are
 * matched through an EXISTS subquery rather than a join, so a post in several
 * categories is still one row, and an event keeps exactly the rows it had.
 * Only published terms match. Tags and other vocabularies are not searched.
 *
 * Only the "contains", "word" and "allwords" operators search categories. The
 * others behave exactly like core's combine filter.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('ys_views_basic_category_combine')]
class CategoryCombine extends Combine {

  /**
   * {@inheritdoc}
   */
  protected function opContains($expression) {
    $placeholder = $this->placeholder();
    $this->query->addWhereExpression($this->options['group'], $this->matchAny($expression, $placeholder), [$placeholder => '%' . $this->connection->escapeLike($this->value) . '%']);
  }

  /**
   * {@inheritdoc}
   */
  protected function opContainsWord($expression) {
    // Don't filter on empty strings.
    if (empty($this->value)) {
      return;
    }

    preg_match_all(static::WORDS_PATTERN, ' ' . $this->value, $matches, PREG_SET_ORDER);
    $placeholder = $this->placeholder();
    $group = $this->query->setWhereGroup($this->operator == 'word' ? 'OR' : 'AND');
    foreach ($matches as $match_key => $match) {
      $temp_placeholder = $placeholder . '_' . $match_key;
      $word = trim($match[2], ',?!();:-"');
      $this->query->addWhereExpression($group, $this->matchAny($expression, $temp_placeholder), [$temp_placeholder => '%' . $this->connection->escapeLike($word) . '%']);
    }
  }

  /**
   * Builds a condition matching the fields or any published category name.
   *
   * @param string $expression
   *   The field expression core's combine filter built.
   * @param string $placeholder
   *   The placeholder holding the LIKE pattern.
   *
   * @return string
   *   An SQL condition, OR'ing both sources.
   */
  protected function matchAny(string $expression, string $placeholder): string {
    $nid = $this->query->ensureTable('node_field_data', $this->relationship) . '.nid';
    $like = $this->getConditionOperator('LIKE');
    return "($expression $like $placeholder OR EXISTS (SELECT 1 FROM {node__field_category} ys_fc INNER JOIN {taxonomy_term_field_data} ys_ct ON ys_ct.tid = ys_fc.field_category_target_id WHERE ys_fc.entity_id = $nid AND ys_fc.deleted = 0 AND ys_ct.status = 1 AND ys_ct.name $like $placeholder))";
  }

}
