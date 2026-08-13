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
   * Builds a batch definition that imports $rows through $import_service_id.
   *
   * @param string $import_service_id
   *   Service id of an import service exposing
   *   processImport(array $rows, bool $skip_duplicates): array, returning
   *   'created', 'skipped', 'errors' and (optionally) 'needs_media' keys.
   * @param array $rows
   *   The full set of already-parsed CSV rows.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   * @param int $file_id
   *   The uploaded file entity id, deleted once the batch finishes.
   * @param string $entity_label
   *   Singular noun for the imported entity, e.g. 'profile' or 'resource'.
   * @param string $title
   *   The batch progress title.
   *
   * @return array
   *   A batch definition suitable for batch_set().
   */
  public static function build($import_service_id, array $rows, $skip_duplicates, $file_id, $entity_label, $title) {
    $operations = [];

    foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
      $operations[] = [
        [static::class, 'processChunk'],
        [$import_service_id, $chunk, $skip_duplicates, $entity_label],
      ];
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
   * @param string $import_service_id
   *   Service id of the import service to invoke.
   * @param array $chunk
   *   The rows in this chunk.
   * @param bool $skip_duplicates
   *   Whether to skip duplicates.
   * @param string $entity_label
   *   Singular noun for the imported entity, carried through to finished().
   * @param array $context
   *   The batch context, passed by reference.
   */
  public static function processChunk($import_service_id, array $chunk, $skip_duplicates, $entity_label, array &$context) {
    if (!isset($context['results']['created'])) {
      $context['results'] = [
        'entity_label' => $entity_label,
        'created' => 0,
        'skipped' => 0,
        'errors' => [],
        'needs_media' => [],
      ];
    }

    $chunk_result = \Drupal::service($import_service_id)->processImport($chunk, $skip_duplicates);

    $context['results']['created'] += $chunk_result['created'];
    $context['results']['skipped'] += $chunk_result['skipped'];
    $context['results']['errors'] = array_merge($context['results']['errors'], $chunk_result['errors']);

    if (!empty($chunk_result['needs_media'])) {
      $context['results']['needs_media'] = array_merge($context['results']['needs_media'], $chunk_result['needs_media']);
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
   *   The accumulated $context['results'] from the last operation.
   * @param array $operations
   *   The operations that were not completed, if any.
   */
  public static function finished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The import could not be completed. Please try again.'));
      return;
    }

    foreach (static::buildMessages($results) as [$method, $message]) {
      $messenger->$method($message);
    }
  }

  /**
   * Builds the [messenger method, message] pairs finished() will display.
   *
   * Kept free of any \Drupal:: calls so the message text and shape can be
   * unit tested without a messenger service.
   *
   * @param array $results
   *   The accumulated results: 'entity_label', 'created', 'skipped',
   *   'errors', 'needs_media'.
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
