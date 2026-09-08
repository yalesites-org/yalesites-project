<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\IndexingBatchHelperInterface;
use Drupal\ys_beacon\PdfTextExtractionBatch;
use Drupal\ys_beacon\Service\BeaconIndexStatus;
use Drupal\ys_beacon\Service\PdfTextIndexer;

/**
 * Shared Beacon indexing controls and their submit handlers.
 *
 * Both the site settings form and the platform administration form expose
 * Beacon indexing controls, so the render array, the "Index now" and
 * "Re-index all content" submit handlers, and the borrowed/read-only guard all
 * live here and neither form duplicates the batch-building logic.
 *
 * What the index's state IS, and the sentences describing it, belong to
 * BeaconIndexStatus rather than to this trait: the Beacon section of the
 * Platform Admin Settings page shows the same status but is a plugin rather
 * than a form, so it cannot use this trait and previously carried its own copy
 * of every read. A consuming form must set $indexStatus,
 * $indexingBatchHelper and $pdfTextIndexer in its create().
 */
trait BeaconIndexingControlsTrait {

  /**
   * The Beacon index status reader.
   *
   * @var \Drupal\ys_beacon\Service\BeaconIndexStatus
   */
  protected BeaconIndexStatus $indexStatus;

  /**
   * The Search API indexing batch helper.
   *
   * @var \Drupal\search_api\Utility\IndexingBatchHelperInterface
   */
  protected IndexingBatchHelperInterface $indexingBatchHelper;

  /**
   * The PDF text indexer.
   *
   * @var \Drupal\ys_beacon\Service\PdfTextIndexer
   */
  protected PdfTextIndexer $pdfTextIndexer;

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
    if ($this->indexStatus->isReadOnly()) {
      // This site borrows another site's collection: content indexing is owned
      // by that site, so the local re-index / index-now controls are hidden and
      // the status is replaced with a short explanatory note.
      $element['status'] = [
        '#markup' => '<p>' . $this->indexStatus->readOnlyNotice() . '</p>',
      ];
      return $element;
    }
    $element['status'] = [
      '#markup' => '<p>' . $this->indexStatus->summary() . '</p>',
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
      '#disabled' => $this->indexStatus->remainingItems() < 1,
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
    $index = $this->indexStatus->load();
    if ($index && $index->isReadOnly()) {
      $this->messenger()->addWarning($this->indexStatus->readOnlyNotice());
      return;
    }
    if ($index && $index->status()) {
      // rebuildTracker() re-enumerates the datasources and marks every item
      // for indexing, so it repopulates a never-seeded tracker as well as
      // re-queueing tracked content; reindex() would only do the latter
      // (issue #1383).
      $index->rebuildTracker();
      // Documents whose text was never extracted would be re-indexed as
      // filename-only chunks, so sweep them in the same click.
      $this->setPdfExtractionBatch();
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
    $index = $this->indexStatus->load();
    if ($index && $index->isReadOnly()) {
      $this->messenger()->addWarning($this->indexStatus->readOnlyNotice());
      return;
    }
    if (!$index || !$index->status()) {
      $this->messenger()->addWarning($this->t('The Beacon index is not enabled on this site. Enable the chat widget first.'));
      return;
    }
    // Extract any missing PDF text first, so a document reaches the vector
    // database with its content rather than just its filename. Batch-set
    // before the Search API batch so the two run in order and no PDF is
    // parsed in this submit handler. Note this button is disabled unless
    // something is already waiting to be indexed, so a site whose only
    // outstanding work is extraction has to use "Re-index all content" or the
    // Drush backfill; $extracting therefore only matters in the race the
    // comment below describes.
    $extracting = $this->setPdfExtractionBatch();
    // Re-check the queue server-side: the button's #disabled state is only
    // evaluated at render time, so a stale page or a queue drained by cron
    // between render and submit could otherwise start an empty batch, which
    // Search API reports as a failure rather than a no-op.
    if ($this->indexStatus->remainingItems() < 1) {
      if (!$extracting) {
        $this->messenger()->addStatus($this->t('There is no content waiting to be indexed.'));
      }
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
   * Sets a batch extracting text from documents that still lack it.
   *
   * @return bool
   *   TRUE when a batch was set, FALSE when no document needed extraction.
   */
  protected function setPdfExtractionBatch(): bool {
    $batch = PdfTextExtractionBatch::build($this->pdfTextIndexer->pendingMediaIds());
    if (!$batch) {
      return FALSE;
    }
    batch_set($batch);
    return TRUE;
  }

}
