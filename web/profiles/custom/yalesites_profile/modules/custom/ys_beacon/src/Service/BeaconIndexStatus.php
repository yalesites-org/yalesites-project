<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\IndexInterface;

/**
 * Reads the state of the Beacon Search API index, and describes it.
 *
 * Three places show a site's indexing state: the site settings form, the
 * platform administration form, and the Beacon section of the Platform Admin
 * Settings page. They previously each carried their own copy of "load the
 * index", "count what the tracker knows", and the sentences describing the
 * result - the platform-admin plugin because it extends a plugin base rather
 * than the ConfigFormBase the forms' shared trait assumed it needed. The copies
 * had already begun to drift: the read-only notice existed as a method whose
 * whole stated purpose was to keep that wording in one place, and as an inlined
 * duplicate of the same sentence.
 *
 * Both the reads and their wording live here so the three surfaces cannot
 * describe the same index differently. Callers do the presentation; this
 * decides only what is true and what to call it.
 */
class BeaconIndexStatus {

  use StringTranslationTrait;

  /**
   * Constructs the Beacon index status reader.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load the index.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to resolve the configured index id.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The Search API index machine name backing the chatbot.
   *
   * @return string
   *   The configured index id, or the "ys_beacon" default.
   */
  public function indexId(): string {
    return $this->configFactory->get('ys_beacon.settings')->get('search_index_id') ?: 'ys_beacon';
  }

  /**
   * Loads the index as the runtime sees it, for display.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index entity, or NULL when it does not exist.
   */
  public function load(): ?IndexInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->indexId());
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Loads the index override-free, for write-side decisions.
   *
   * Reads the stored configuration rather than the runtime-resolved view, so
   * the status and read-only overrides layered on by YsBeaconConfigOverrides
   * are neither mistaken for stored state nor baked into the synced
   * search_api.index config.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index entity, or NULL when it does not exist.
   */
  public function loadOverrideFree(): ?IndexInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')
      ->loadOverrideFree($this->indexId());
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Whether this site borrows another site's read-only collection.
   *
   * @return bool
   *   TRUE when the index exists and is read-only.
   */
  public function isReadOnly(): bool {
    return (bool) $this->load()?->isReadOnly();
  }

  /**
   * Counts tracked items not yet indexed into the Beacon vector database.
   *
   * @return int
   *   The remaining item count, or 0 when the index is missing, disabled, or
   *   its tracker cannot be read - so the "Index now" control stays disabled in
   *   each of those states rather than starting an empty batch.
   */
  public function remainingItems(): int {
    $index = $this->load();
    if (!$index || !$index->status()) {
      return 0;
    }
    try {
      return (int) $index->getTrackerInstance()->getRemainingItemsCount();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * The number of items the index's tracker knows about.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to count, passed in because a caller deciding whether to seed
   *   the tracker holds the override-free entity rather than the displayed one.
   *
   * @return int
   *   The tracked item count, or 0 when the tracker cannot be read - so an
   *   unreadable tracker errs towards seeding rather than silently leaving a
   *   site indexing nothing, which is the failure this count exists to prevent.
   */
  public function trackedItems(IndexInterface $index): int {
    try {
      return (int) $index->getTrackerInstance()->getTotalItemsCount();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * A short indexing status summary.
   *
   * @return string
   *   The "@indexed of @total items indexed" count, or an explanation of why no
   *   count is available.
   */
  public function summary(): string {
    $index = $this->load();
    if (!$index || !$index->status()) {
      return (string) $this->t('The Beacon index is currently disabled. It enables automatically once the chat widget is turned on.');
    }
    try {
      $tracker = $index->getTrackerInstance();
      return (string) $this->t('@indexed of @total items indexed.', [
        '@indexed' => $tracker->getIndexedItemsCount(),
        '@total' => $tracker->getTotalItemsCount(),
      ]);
    }
    catch (\Throwable $e) {
      return (string) $this->t('Index status unavailable.');
    }
  }

  /**
   * The status text for a display that shows no indexing controls.
   *
   * @return string
   *   The shared-collection notice when this site borrows a read-only index,
   *   otherwise the item-count summary.
   */
  public function statusMarkup(): string {
    return $this->isReadOnly()
      ? (string) $this->readOnlyNotice()
      : $this->summary();
  }

  /**
   * The note shown when the Beacon index borrows another site's collection.
   *
   * Displayed in place of the indexing controls, and returned by the indexing
   * submit handlers when they are blocked, so the wording lives in one place.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The read-only notice.
   */
  public function readOnlyNotice(): TranslatableMarkup {
    return $this->t('This site uses a shared, read-only index; content indexing is managed by the owning site.');
  }

}
