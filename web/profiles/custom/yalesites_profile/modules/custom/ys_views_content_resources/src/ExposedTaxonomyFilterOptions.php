<?php

namespace Drupal\ys_views_content_resources;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Constrains the options offered by an exposed taxonomy filter.
 *
 * Works on the `filters` option array of a Views display, for any filter of
 * plugin `taxonomy_index_tid`. Two constraints are supported, alone or
 * together:
 *
 * - A parent term: only that term's descendants are offered.
 * - Excluded terms: any term the editor used to exclude content is removed.
 *   A visitor picking such a term would always get zero results, so it must
 *   never be an option.
 *
 * The vocabulary is read from the filter's own `vid` setting, so no
 * filter-to-vocabulary map is needed; excluded term ids from any vocabulary
 * can be passed and only those in the filter's vocabulary take effect.
 *
 * The filter config is left untouched when nothing constrains it (no parent
 * and no excluded id in its vocabulary), so existing blocks keep offering the
 * whole vocabulary.
 *
 * Not tied to the resources view. Any module that assembles a Views display's
 * filters from stored parameters (for example `ys_views_basic`) can use the
 * service `ys_views_content_resources.exposed_taxonomy_filter_options`, or
 * copy this class, and call apply() per taxonomy filter.
 */
class ExposedTaxonomyFilterOptions {

  /**
   * The taxonomy term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected TermStorageInterface $termStorage;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->termStorage = $entity_type_manager->getStorage('taxonomy_term');
    $this->logger = $logger;
  }

  /**
   * Constrains one exposed taxonomy filter's options in place.
   *
   * @param array $filters
   *   The display's `filters` option array, modified by reference.
   * @param string $filter_name
   *   The filter id within $filters (for example `field_category_target_id`).
   * @param int[] $excluded_tids
   *   Term ids that must not be offered. Ids outside the filter's vocabulary
   *   are ignored.
   * @param int|null $parent_tid
   *   Optional parent term id; when set, only its descendants are offered.
   *
   * @return bool
   *   TRUE when the filter was constrained, FALSE when it was left untouched
   *   (missing filter, no vocabulary on it, or nothing to constrain). A filter
   *   without a vocabulary is logged as a warning.
   */
  public function apply(array &$filters, string $filter_name, array $excluded_tids, ?int $parent_tid = NULL): bool {
    if (!isset($filters[$filter_name])) {
      return FALSE;
    }

    $excluded_tids = array_map('intval', $excluded_tids);
    $parent_tid = $parent_tid ?: NULL;

    // Nothing constrains the filter: leave the full vocabulary available.
    if ($parent_tid === NULL && !$excluded_tids) {
      return FALSE;
    }

    $vid = $filters[$filter_name]['vid'] ?? NULL;
    if (!$vid) {
      $this->logger->warning('Exposed filter @filter has no vocabulary, so its options cannot be constrained.', ['@filter' => $filter_name]);
      return FALSE;
    }

    // Keep only excluded ids in this filter's vocabulary; others could never
    // be offered by it. Term loads are statically cached, so repeat calls per
    // filter are cheap.
    $excluded_tids = $excluded_tids ? array_keys(array_filter(
      $this->termStorage->loadMultiple($excluded_tids),
      static fn ($term) => $term->bundle() === $vid,
    )) : [];

    if ($parent_tid === NULL && !$excluded_tids) {
      return FALSE;
    }

    $available = $this->getDescendantTermIds($vid, $parent_tid ?? 0);
    $available = static::reduceTermsForExposure($available, $excluded_tids);

    $filters[$filter_name]['value'] = $available;
    $filters[$filter_name]['limit'] = TRUE;
    $filters[$filter_name]['expose']['reduce'] = TRUE;

    return TRUE;
  }

  /**
   * Removes excluded term ids from a set of exposed filter options.
   *
   * Pure helper, separated for unit testing and for callers that already
   * hold the available set.
   *
   * @param array $available
   *   Available term options keyed by term id.
   * @param int[] $excluded
   *   Term ids to remove.
   *
   * @return array
   *   The available terms with the excluded ids removed.
   */
  public static function reduceTermsForExposure(array $available, array $excluded): array {
    if (!$excluded) {
      return $available;
    }
    return array_diff_key($available, array_flip($excluded));
  }

  /**
   * Loads the ids of every descendant of a term (or of a whole vocabulary).
   *
   * @param string $vid
   *   The vocabulary id.
   * @param int $parent_tid
   *   The parent term id, or 0 for the whole vocabulary.
   *
   * @return int[]
   *   Term ids keyed by term id.
   */
  protected function getDescendantTermIds(string $vid, int $parent_tid): array {
    $list = [];
    foreach ($this->termStorage->loadTree($vid, $parent_tid, NULL) as $term) {
      $list[$term->tid] = (int) $term->tid;
    }
    return $list;
  }

}
