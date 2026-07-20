<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\IndexingBatchHelperInterface;

/**
 * Shared Beacon indexing status display and indexing submit handlers.
 *
 * Both the site settings form and the platform administration form expose
 * Beacon indexing controls, so the render array, the "Index now" and
 * "Re-index all content" submit handlers, and the borrowed/read-only guard all
 * live here and neither form duplicates the batch-building logic. A consuming
 * form must be a ConfigFormBase subclass (for config(), messenger(), and t())
 * and must set $entityTypeManager and $indexingBatchHelper in its create().
 */
trait BeaconIndexingControlsTrait {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Search API indexing batch helper.
   *
   * @var \Drupal\search_api\Utility\IndexingBatchHelperInterface
   */
  protected IndexingBatchHelperInterface $indexingBatchHelper;

  /**
   * Builds the "Indexing" details with the status summary and controls.
   *
   * When the index borrows another site's read-only collection the controls are
   * hidden and replaced with the read-only notice, so the guard is carried to
   * whichever form hosts the controls.
   *
   * @param bool $include_reindex
   *   Whether to include the "Re-index all content" button. The administration
   *   form hosts both controls; the site settings form mirrors only
   *   "Index now".
   *
   * @return array
   *   The render array for the indexing details element.
   */
  protected function buildIndexingControls(bool $include_reindex): array {
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Indexing'),
      '#open' => TRUE,
      '#weight' => 30,
    ];
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      // This site borrows another site's collection: content indexing is owned
      // by that site, so the local re-index / index-now controls are hidden and
      // the status is replaced with a short explanatory note.
      $element['status'] = [
        '#markup' => '<p>' . $this->readOnlyNotice() . '</p>',
      ];
      return $element;
    }
    $element['status'] = [
      '#markup' => '<p>' . $this->indexStatusSummary() . '</p>',
    ];
    if ($include_reindex) {
      $element['reindex'] = [
        '#type' => 'submit',
        '#value' => $this->t('Re-index all content'),
        '#submit' => ['::reindexAll'],
        '#limit_validation_errors' => [],
      ];
    }
    $element['index_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Index now'),
      // Dedicated handler only: the main config submit must not run, so the
      // form's settings are not saved when the user just wants to flush the
      // queue.
      '#submit' => ['::indexNow'],
      '#limit_validation_errors' => [],
      // Disabled unless the Beacon index is enabled and has items waiting to
      // be indexed. Mirrors Search API's own "Index now" disabled behaviour.
      '#disabled' => $this->indexRemainingItems() < 1,
    ];
    return $element;
  }

  /**
   * Submit handler queueing all content for re-indexing.
   *
   * Replaces the legacy "Upsert All Documents" action: items are re-tracked
   * and re-embedded into the vector database on the next indexing runs.
   */
  public function reindexAll(array &$form, FormStateInterface $form_state): void {
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      $this->messenger()->addWarning($this->readOnlyNotice());
      return;
    }
    if ($index && $index->status()) {
      // rebuildTracker() re-enumerates the datasources and marks every item
      // for indexing, so it repopulates a never-seeded tracker as well as
      // re-queueing tracked content; reindex() would only do the latter
      // (issue #1383).
      $index->rebuildTracker();
      $this->messenger()->addStatus($this->t('All content has been queued for re-indexing into the Beacon vector database.'));
    }
    else {
      $this->messenger()->addWarning($this->t('The Beacon index is not enabled on this site. Enable the chat widget first.'));
    }
  }

  /**
   * Submit handler running the Search API indexing batch for the Beacon index.
   *
   * Calls Search API's indexing batch helper directly so the only Search API
   * capability exposed to administrators is indexing this one index; no
   * "administer search_api" permission or Search API route is required. Batch
   * size and limit are intentionally omitted so the index's own defaults are
   * used (all remaining items, in batches of the index cron_limit). Drupal's
   * Form API runs the queued batch and returns the user to this form.
   */
  public function indexNow(array &$form, FormStateInterface $form_state): void {
    $index = $this->loadBeaconIndex();
    if ($index && $index->isReadOnly()) {
      $this->messenger()->addWarning($this->readOnlyNotice());
      return;
    }
    if (!$index || !$index->status()) {
      $this->messenger()->addWarning($this->t('The Beacon index is not enabled on this site. Enable the chat widget first.'));
      return;
    }
    // Re-check the queue server-side: the button's #disabled state is only
    // evaluated at render time, so a stale page or a queue drained by cron
    // between render and submit could otherwise start an empty batch, which
    // Search API reports as a failure rather than a no-op.
    if ($this->indexRemainingItems() < 1) {
      $this->messenger()->addStatus($this->t('There is no content waiting to be indexed.'));
      return;
    }
    try {
      $this->indexingBatchHelper->createBatch($index);
    }
    catch (SearchApiException $e) {
      $this->messenger()->addWarning($this->t('Unable to start indexing right now. Please try again shortly.'));
    }
  }

  /**
   * Builds a short indexing status summary.
   */
  protected function indexStatusSummary(): string {
    $index = $this->loadBeaconIndex();
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
   * The index status text for a read-only display.
   *
   * Returns the shared-collection notice when this site borrows a read-only
   * index, otherwise the "@indexed of @total items indexed" summary. Used where
   * the status is shown without the indexing controls (the site settings form).
   *
   * @return string
   *   The status text.
   */
  protected function indexStatusMarkup(): string {
    $index = $this->loadBeaconIndex();
    return $index && $index->isReadOnly()
      ? (string) $this->readOnlyNotice()
      : $this->indexStatusSummary();
  }

  /**
   * Counts tracked items not yet indexed into the Beacon vector database.
   *
   * Returns 0 when the index is missing or disabled so the "Index now" button
   * stays disabled in those states.
   */
  protected function indexRemainingItems(): int {
    $index = $this->loadBeaconIndex();
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
   * The note shown when the Beacon index borrows another site's collection.
   *
   * Displayed in place of the indexing controls and returned by the indexing
   * submit handlers when they are blocked, so the wording lives in one place.
   */
  protected function readOnlyNotice(): TranslatableMarkup {
    return $this->t('This site uses a shared, read-only index; content indexing is managed by the owning site.');
  }

  /**
   * Loads the Search API index backing the Beacon chatbot.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index entity, or NULL when it does not exist.
   */
  protected function loadBeaconIndex(): ?IndexInterface {
    return $this->entityTypeManager->getStorage('search_api_index')->load($this->searchIndexId());
  }

  /**
   * The Search API index machine name backing the chatbot.
   */
  protected function searchIndexId(): string {
    return $this->config(static::CONFIG_NAME)->get('search_index_id') ?: 'ys_beacon';
  }

}
