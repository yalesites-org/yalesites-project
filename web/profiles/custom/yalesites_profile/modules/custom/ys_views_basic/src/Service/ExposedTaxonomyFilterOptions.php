<?php

namespace Drupal\ys_views_basic\Service;

use Drupal\Core\Database\Connection;
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
 * - Terms in use: only terms tagged on content the listing can show, by
 *   node type, publish date and event time period. Listings migrated from
 *   the predecessor Post Feed, Calendar List and Directory blocks ask for
 *   this, because those Views only listed terms in use
 *   (YaleSites-Internal#1967).
 *
 * The vocabulary is read from the filter's own `vid` setting, so no
 * filter-to-vocabulary map is needed; excluded term ids from any vocabulary
 * can be passed and only those in the filter's vocabulary take effect.
 *
 * The filter config is left untouched when nothing constrains it (no parent
 * and no excluded id in its vocabulary), so existing blocks keep offering the
 * whole vocabulary.
 *
 * Not tied to the resources view: any code that assembles a Views display's
 * filters from stored parameters can use the service
 * `ys_views_basic.exposed_taxonomy_filter_options` and call apply() per
 * taxonomy filter. ViewsBasicManager uses it for every listing type.
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
   * The database connection, for the taxonomy_index lookup.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, Connection $database) {
    $this->termStorage = $entity_type_manager->getStorage('taxonomy_term');
    $this->logger = $logger;
    $this->database = $database;
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
   * @param array|null $used_in
   *   Optional content scope; when set, only terms tagged on content in it
   *   are offered. Keys: 'types' (node types) and 'event_time_period'
   *   (future, past or all). See ::usedTermIds().
   *
   * @return bool
   *   TRUE when the filter was constrained, FALSE when it was left untouched
   *   (missing filter, no vocabulary on it, or nothing to constrain). A filter
   *   without a vocabulary is logged as a warning.
   */
  public function apply(array &$filters, string $filter_name, array $excluded_tids, ?int $parent_tid = NULL, ?array $used_in = NULL): bool {
    if (!isset($filters[$filter_name])) {
      return FALSE;
    }

    $excluded_tids = array_map('intval', $excluded_tids);
    $parent_tid = $parent_tid ?: NULL;

    // Nothing constrains the filter: leave the full vocabulary available.
    if ($parent_tid === NULL && !$excluded_tids && $used_in === NULL) {
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

    if ($parent_tid === NULL && !$excluded_tids && $used_in === NULL) {
      return FALSE;
    }

    $available = $this->getDescendantTermIds($vid, $parent_tid ?? 0);
    $available = static::reduceTermsForExposure($available, $excluded_tids);
    if ($used_in !== NULL) {
      $available = $this->usedTermIds($available, $used_in['types'] ?? [], $used_in['event_time_period'] ?? NULL);
    }

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
   * Builds the element shown in place of an exposed filter with no choices.
   *
   * A disabled, empty Chosen multi-select keeps the filter's box and label in
   * the row, with the message as its placeholder and title. Chosen exposes
   * the placeholder as the name of its disabled combobox, so screen readers
   * announce it. This replaces the old YaleSites-Internal#162 rule that hid
   * such filters.
   *
   * @param mixed $title
   *   The filter label.
   *
   * @return array
   *   A Form API select element.
   */
  public static function emptyFilterElement($title): array {
    $message = t('No options available.');
    return [
      '#type' => 'select',
      '#title' => $title,
      '#multiple' => TRUE,
      '#options' => [],
      '#disabled' => TRUE,
      '#chosen' => TRUE,
      '#attributes' => [
        'class' => ['ys-filter--empty'],
        'data-placeholder' => $message,
        // Chosen copies the select's title onto its container, so the full
        // message shows on hover when it is truncated.
        'title' => $message,
      ],
    ];
  }

  /**
   * Keeps only the given terms tagged on content a listing can show.
   *
   * Reads taxonomy_index, which core keeps for published content only. Like
   * the listing query, a post listing skips posts dated in the future
   * (PostPublishDate) and an event listing honours its time period
   * (EventTimePeriod), so a term used only on past events is not offered by
   * an upcoming-events listing, as on the predecessor Views.
   *
   * @param int[] $tids
   *   Candidate term ids, keyed by term id.
   * @param string[] $node_types
   *   The listing's node types (bundles).
   * @param string|null $event_time_period
   *   The event time period: 'future', 'past', or anything else for all.
   *
   * @return int[]
   *   The candidates in use, keyed by term id.
   */
  public function usedTermIds(array $tids, array $node_types, ?string $event_time_period = NULL): array {
    if (!$tids || !$node_types) {
      return [];
    }
    $query = $this->database->select('taxonomy_index', 'ti');
    $query->innerJoin('node_field_data', 'n', '[n].[nid] = [ti].[nid]');
    $query->condition('n.type', $node_types, 'IN')
      ->condition('ti.tid', array_keys($tids), 'IN')
      ->distinct();
    if ($node_types === ['post']) {
      $query->leftJoin('node__field_publish_date', 'pd', '[pd].[entity_id] = [n].[nid]');
      $query->condition('pd.field_publish_date_value', date('Y-m-d'), '<=');
    }
    $operator = ['future' => '>=', 'past' => '<'][$event_time_period] ?? NULL;
    if ($operator && in_array('event', $node_types, TRUE)) {
      $query->innerJoin('node__field_event_date', 'ed', '[ed].[entity_id] = [n].[nid]');
      $query->condition('ed.field_event_date_end_value', time(), $operator);
    }
    $query->addField('ti', 'tid');
    $used = array_map('intval', $query->execute()->fetchCol());
    return array_intersect_key($tids, array_flip($used));
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
  public function getDescendantTermIds(string $vid, int $parent_tid): array {
    $list = [];
    foreach ($this->termStorage->loadTree($vid, $parent_tid, NULL) as $term) {
      $list[$term->tid] = (int) $term->tid;
    }
    return $list;
  }

}
