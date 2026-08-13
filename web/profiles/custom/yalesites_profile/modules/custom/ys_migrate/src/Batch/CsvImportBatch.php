<?php

namespace Drupal\ys_migrate\Batch;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Batch API glue shared by the profile and resource CSV importers.
 *
 * A CSV of any real size timed out in a single request before this existed.
 * Drupal stores batch operations as serialized callback strings and may
 * invoke each one in a separate request, so every method here is static and
 * re-fetches services through \Drupal:: rather than relying on injected
 * state that would not survive across requests.
 */
class CsvImportBatch {

  /**
   * Rows processed per batch operation (one HTTP request each).
   *
   * Large enough that a several-hundred-row import doesn't spend most of its
   * wall-clock time on per-request overhead; small enough to keep even a
   * slow chunk (taxonomy term creation, node saves) well under typical PHP
   * execution time limits.
   */
  const CHUNK_SIZE = 50;

  /**
   * Builds a batch definition that imports $rows through $options.
   *
   * @param array $options
   *   Batch options: 'import_service_id' (service id of an import service
   *   exposing processImport(array $rows, bool $skip_duplicates): array,
   *   returning 'created', 'skipped', 'errors' and, optionally,
   *   'needs_media' keys), 'skip_duplicates' (whether to skip duplicates),
   *   and 'entity_label' (singular noun for the imported entity, e.g.
   *   'profile' or 'resource').
   * @param array $rows
   *   The full set of already-parsed CSV rows.
   * @param int $file_id
   *   The uploaded file entity id, deleted once the batch finishes.
   * @param string $title
   *   The batch progress title.
   *
   * @return array
   *   A batch definition suitable for batch_set().
   */
  public static function build(array $options, array $rows, $file_id, $title) {
    $operations = [];

    foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
      $operations[] = [[static::class, 'processChunk'], [$options, $chunk]];
    }

    // Runs last regardless of how many chunks preceded it, so the upload
    // is cleaned up exactly once the whole import (not just one chunk) has
    // been processed.
    $operations[] = [[static::class, 'deleteUploadedFile'], [$file_id]];

    return [
      'title' => $title,
      'operations' => $operations,
      'finished' => [static::class, 'finished'],
    ];
  }

  /**
   * Batch operation: imports one chunk and folds its results into $context.
   *
   * Each chunk's 'errors' and 'needs_media' are appended as their own
   * sub-array rather than merged into a single running list, so this stays
   * O(1) per chunk regardless of how many rows have failed or need media so
   * far; finished() flattens the sub-arrays once, at the end.
   *
   * @param array $options
   *   The 'import_service_id', 'skip_duplicates' and 'entity_label' options
   *   described in build().
   * @param array $chunk
   *   The rows in this chunk.
   * @param array $context
   *   The batch context, passed by reference.
   */
  public static function processChunk(array $options, array $chunk, array &$context) {
    if (!isset($context['results']['created'])) {
      $context['results'] = [
        'entity_label' => $options['entity_label'],
        'created' => 0,
        'skipped' => 0,
        'errors' => [],
        'needs_media' => [],
      ];
    }

    $chunk_result = \Drupal::service($options['import_service_id'])->processImport($chunk, $options['skip_duplicates']);

    $context['results']['created'] += $chunk_result['created'];
    $context['results']['skipped'] += $chunk_result['skipped'];
    $context['results']['errors'][] = $chunk_result['errors'];

    if (!empty($chunk_result['needs_media'])) {
      $context['results']['needs_media'][] = $chunk_result['needs_media'];
    }
  }

  /**
   * Batch operation: deletes the uploaded CSV once the import has run.
   *
   * @param int $file_id
   *   The file entity id.
   * @param array $context
   *   The batch context, passed by reference.
   */
  public static function deleteUploadedFile($file_id, array &$context) {
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
    if ($file) {
      $file->delete();
    }
  }

  /**
   * Batch finished callback: reports the accumulated results to the user.
   *
   * @param bool $success
   *   Whether the batch completed without a fatal error.
   * @param array $results
   *   The accumulated $context['results'] from the last operation that ran,
   *   with 'errors' and 'needs_media' still shaped as one sub-array per
   *   chunk. Empty if the batch failed before its first chunk completed.
   * @param array $operations
   *   The operations that were queued but never reached, if the batch
   *   stopped early.
   */
  public static function finished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if (!$success) {
      // Chunks that completed before the failure already saved their nodes,
      // so their counts are reported rather than discarded -- otherwise an
      // editor who sees no results assumes nothing happened and re-runs the
      // whole file, creating duplicates of rows that did succeed.
      if (isset($results['created'])) {
        $results['errors'] = static::flatten($results['errors'] ?? []);
        $results['needs_media'] = static::flatten($results['needs_media'] ?? []);
        foreach (static::buildMessages($results) as [$method, $message]) {
          $messenger->$method($message);
        }
      }

      // deleteUploadedFile() is normally the batch's own trailing operation,
      // so it never runs if an earlier chunk aborted the batch; run it here
      // from its still-queued arguments instead of leaving the upload behind.
      foreach ($operations as $operation) {
        if ($operation[0] === [static::class, 'deleteUploadedFile']) {
          $unused_context = [];
          static::deleteUploadedFile($operation[1][0], $unused_context);
          break;
        }
      }

      $messenger->addError(new TranslatableMarkup('The import did not finish. Check the results above, then try again if needed.'));
      return;
    }

    $results['errors'] = static::flatten($results['errors'] ?? []);
    $results['needs_media'] = static::flatten($results['needs_media'] ?? []);

    foreach (static::buildMessages($results) as [$method, $message]) {
      $messenger->$method($message);
    }
  }

  /**
   * Flattens a list of per-chunk sub-arrays into one array, once.
   *
   * @param array $chunks
   *   A list of arrays, one per batch chunk.
   *
   * @return array
   *   All chunk values combined into a single flat list.
   */
  protected static function flatten(array $chunks) {
    return $chunks ? array_merge(...$chunks) : [];
  }

  /**
   * Builds the [messenger method, message] pairs finished() will display.
   *
   * Kept free of any \Drupal:: calls so the message text and shape can be
   * unit tested without a messenger service.
   *
   * @param array $results
   *   The accumulated results: 'entity_label', 'created', 'skipped', and
   *   already-flat 'errors' and 'needs_media' lists.
   *
   * @return array
   *   A list of [messenger method name, message] pairs.
   */
  public static function buildMessages(array $results) {
    $label = $results['entity_label'] ?? 'item';
    $messages = [];

    if (!empty($results['created'])) {
      $messages[] = [
        'addStatus',
        new TranslatableMarkup('Created @count @label(s).', ['@count' => $results['created'], '@label' => $label]),
      ];
    }

    if (!empty($results['skipped'])) {
      $messages[] = [
        'addWarning',
        new TranslatableMarkup(
          'Skipped @count duplicate @label(s).',
          ['@count' => $results['skipped'], '@label' => $label]
        ),
      ];
    }

    if (!empty($results['needs_media'])) {
      $messages[] = [
        'addWarning',
        new TranslatableMarkup(
          '@count imported resource(s) have no External Source and still need Resource Media attached: @titles', [
            '@count' => count($results['needs_media']),
            '@titles' => implode(', ', $results['needs_media']),
          ]
        ),
      ];
    }

    foreach ($results['errors'] ?? [] as $error) {
      $messages[] = ['addError', $error];
    }

    return $messages;
  }

}
