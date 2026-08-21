<?php

namespace Drupal\ys_beacon;

/**
 * Batch callbacks extracting PDF text for documents that still lack it.
 *
 * Both the "Index now" / "Re-index all content" controls and the backfill Drush
 * command need the same sweep, and it has to run in a batch: parsing PDFs is
 * pure PHP and a library of a few hundred documents would time out a form
 * submit. Methods are static because Drupal's Batch API serializes callback
 * names as strings and may invoke them in a separate PHP request.
 */
class PdfTextExtractionBatch {

  /**
   * How many documents one batch operation examines.
   *
   * One: a batch operation cannot be interrupted part-way, so this is the only
   * value that keeps a single request bounded by a single parse. Documents may
   * be up to pdf_extraction_max_bytes (20 MB by default) of pure-PHP parsing,
   * and several of those in one request would risk max_execution_time on the
   * web tier. Drupal runs as many operations per request as its own time limit
   * allows, so the extra operations cost progress-bar granularity, not speed.
   */
  protected const CHUNK = 1;

  /**
   * Builds the batch definition for the documents still owed extraction.
   *
   * @param array $media_ids
   *   The media ids to sweep, from PdfTextIndexer::pendingMediaIds().
   *
   * @return array|null
   *   A batch definition, or NULL when there is nothing to sweep and the
   *   caller should not start a batch at all.
   */
  public static function build(array $media_ids): ?array {
    if (!$media_ids) {
      return NULL;
    }
    $ids = array_values($media_ids);
    $operations = [];
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      $operations[] = [[static::class, 'process'], [$chunk]];
    }
    return [
      'title' => t('Extracting text from PDF documents'),
      'operations' => $operations,
      'finished' => [static::class, 'finished'],
      'progress_message' => t('Checked @current of @total document batches.'),
    ];
  }

  /**
   * Extracts text for one chunk of media ids.
   *
   * @param array $media_ids
   *   The media ids to examine.
   * @param array $context
   *   The batch context.
   */
  public static function process(array $media_ids, array &$context): void {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $indexer = \Drupal::service('ys_beacon.pdf_text_indexer');
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $storage = \Drupal::entityTypeManager()->getStorage('media');
    $context['results']['checked'] ??= 0;

    foreach ($storage->loadMultiple($media_ids) as $media) {
      // pendingMediaIds() only narrows the field; needsExtraction() is the
      // authority, and it is re-checked here because the sweep can be queued
      // well before the operation runs.
      if (!$indexer->needsExtraction($media)) {
        continue;
      }
      $indexer->extractAndStore($media->id());
      $context['results']['checked']++;
    }
  }

  /**
   * Reports how many documents were processed.
   *
   * @param bool $success
   *   Whether the batch completed without an uncaught error.
   * @param array $results
   *   The accumulated batch results.
   * @param array $operations
   *   Any operations that did not run.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addWarning(t('PDF text extraction did not finish. Any documents it did not reach are picked up by the next run.'));
      return;
    }
    $checked = $results['checked'] ?? 0;
    if ($checked) {
      // "Checked", not "extracted": a scanned, corrupt, oversized or
      // unreadable PDF is processed here but legitimately stores no text.
      $messenger->addStatus(t('Checked @count PDF document(s) for extractable text.', ['@count' => $checked]));
    }
  }

}
