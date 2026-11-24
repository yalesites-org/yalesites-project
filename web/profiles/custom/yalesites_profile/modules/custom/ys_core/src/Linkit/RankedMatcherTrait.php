<?php

namespace Drupal\ys_core\Linkit;

use Drupal\linkit\Suggestion\SuggestionCollection;

/**
 * Orders a Linkit entity matcher's suggestions by how well the label matches.
 *
 * Stock Linkit finds every entity whose label contains the search string, sorts
 * them A-Z and keeps the first N (the matcher's "limit", 100 on our profile).
 * A page whose title is exactly what the editor typed can therefore land deep
 * in the list - on one site "Registration" was suggestion 26 of 228, behind
 * every longer title that sorts ahead of it - or fall past the limit and never
 * be suggested at all.
 * Typing more cannot narrow an exact title any further, so the only way to link
 * it was to paste its URL.
 *
 * This runs the stock query three times, narrowing it each time: labels equal
 * to the search string, then labels starting with it, then the rest. Each pass
 * excludes what an earlier pass found, keeps the stock A-Z order within its own
 * results and takes only the slots that remain under the limit, so an exact
 * title is always suggested first and the total is unchanged.
 *
 * LIKE rather than "=" keeps the exact pass case-insensitive on every database
 * driver; core's PostgreSQL driver maps LIKE to ILIKE.
 *
 * Applied to Linkit's entity matchers by ys_core_linkit_matcher_alter(). Only
 * usable on a subclass of \Drupal\linkit\Plugin\Linkit\Matcher\EntityMatcher.
 *
 * @see \Drupal\linkit\Plugin\Linkit\Matcher\EntityMatcher::execute()
 */
trait RankedMatcherTrait {

  /**
   * {@inheritdoc}
   */
  public function execute($string) {
    $entity_type = $this->entityTypeManager->getDefinition($this->targetType);
    $label_key = $entity_type->getKey('label');
    $escaped = $this->database->escapeLike($string);

    // A pasted URL is resolved to its one entity by buildEntityQuery(), and an
    // entity type without a label has nothing to rank by. Both stay stock.
    if (!$label_key || $this->findEntityIdByUrl($escaped)) {
      return parent::execute($string);
    }

    $id_key = $entity_type->getKey('id');
    $limit = (int) $this->configuration['limit'];
    $base_query = $this->buildEntityQuery($string);
    $ids = [];

    // NULL is the stock query unnarrowed: every label containing the string.
    foreach ([$escaped, $escaped . '%', NULL] as $label_pattern) {
      $remaining = $limit - count($ids);
      if ($limit && $remaining <= 0) {
        break;
      }
      $query = clone $base_query;
      $query->accessCheck(TRUE);
      if ($label_pattern !== NULL) {
        $query->condition($label_key, $label_pattern, 'LIKE');
      }
      if ($ids) {
        $query->condition($id_key, $ids, 'NOT IN');
      }
      if ($limit) {
        $query->range(0, $remaining);
      }
      $ids = array_merge($ids, array_values($query->execute()));
    }

    $suggestions = new SuggestionCollection();
    if (!$ids) {
      return $suggestions;
    }

    // loadMultiple() returns entities in the order of the IDs it is given. The
    // rest is EntityMatcher::execute() unchanged.
    $entities = $this->entityTypeManager->getStorage($this->targetType)->loadMultiple($ids);
    foreach ($entities as $entity) {
      if (!$entity->access('view', $this->currentUser, TRUE)->isAllowed()) {
        continue;
      }
      $entity = $this->entityRepository->getTranslationFromContext($entity);
      $suggestions->addSuggestion($this->createSuggestion($entity));
    }

    return $suggestions;
  }

}
