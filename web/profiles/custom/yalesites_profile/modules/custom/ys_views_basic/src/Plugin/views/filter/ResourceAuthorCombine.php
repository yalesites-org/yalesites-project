<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\Combine;

/**
 * Resource search that also matches author names.
 *
 * Swapped in for the resource view's combine filter when a block selects the
 * "Authors" search field (see ViewsBasicManager::applyResourceFilters()). The
 * selected real fields are matched as core's combine filter does; authors are
 * matched through EXISTS subqueries rather than joins, so a resource with
 * several authors is still one row: affiliated authors by the title of the
 * Profile node field_authors references, non-affiliated authors by either
 * name column of field_nonaffiliated_authors.
 *
 * Only the "contains", "word" and "allwords" operators search authors. The
 * others behave exactly like core's combine filter over the real fields.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("ys_views_basic_resource_author_combine")
 */
class ResourceAuthorCombine extends Combine {

  /**
   * {@inheritdoc}
   *
   * Core adds no condition at all when no real field is selected, which would
   * make an Authors-only search match everything.
   */
  public function query() {
    if ($this->options['fields']) {
      parent::query();
    }
    elseif (in_array($this->operator, ['contains', 'word', 'allwords'], TRUE)) {
      $this->{$this->operators()[$this->operator]['method']}(NULL);
    }
  }

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
   * Builds a condition matching the real fields or any author name.
   *
   * @param string|null $expression
   *   The real-field expression core's combine filter built, or NULL when
   *   only Authors is searched.
   * @param string $placeholder
   *   The placeholder holding the LIKE pattern.
   *
   * @return string
   *   An SQL condition, OR'ing every source.
   */
  protected function matchAny(?string $expression, string $placeholder): string {
    $nid = $this->query->ensureTable('node_field_data', $this->relationship) . '.nid';
    $like = $this->getConditionOperator('LIKE');
    $conditions = [
      "EXISTS (SELECT 1 FROM {node__field_authors} ys_fa INNER JOIN {node_field_data} ys_fp ON ys_fp.nid = ys_fa.field_authors_target_id WHERE ys_fa.entity_id = $nid AND ys_fp.status = 1 AND ys_fp.title $like $placeholder)",
      "EXISTS (SELECT 1 FROM {node__field_nonaffiliated_authors} ys_fn WHERE ys_fn.entity_id = $nid AND (ys_fn.field_nonaffiliated_authors_first $like $placeholder OR ys_fn.field_nonaffiliated_authors_second $like $placeholder))",
    ];
    if ($expression) {
      array_unshift($conditions, "$expression $like $placeholder");
    }
    return '(' . implode(' OR ', $conditions) . ')';
  }

}
