<?php

namespace Drupal\ys_migrate\Service;

/**
 * Contract for a service that imports content from parsed CSV rows.
 *
 * CsvImportBatch resolves an import service from a string id carried in the
 * batch options and calls processImport() on whatever it gets back, so there
 * is no type at that call site to guarantee the two importers stay in step.
 * This interface is that guarantee: it is what CsvImportBatch checks before
 * it calls, and what a third importer would implement to become usable by the
 * same batch machinery without touching it.
 *
 * Rows arrive already parsed and normalised by CsvValidatorService, keyed by
 * lowercased column header, and may carry a '_row_number' key holding the
 * true CSV line so messages can name the line the editor sees.
 */
interface CsvImportServiceInterface {

  /**
   * Imports the given rows, creating content.
   *
   * @param array $data
   *   The parsed CSV rows.
   * @param bool $skip_duplicates
   *   Whether to skip rows that duplicate existing content.
   *
   * @return array
   *   Import results, which always include:
   *   - 'created': int, the number of items created.
   *   - 'skipped': int, the number of rows skipped as duplicates.
   *   - 'errors': array, one message per row that could not be imported.
   *   An implementation may add further keys; CsvImportBatch understands
   *   'needs_media' and ignores anything else.
   */
  public function processImport(array $data, $skip_duplicates);

  /**
   * Reports what importing the given rows would do, creating nothing.
   *
   * @param array $data
   *   The parsed CSV rows.
   * @param bool $skip_duplicates
   *   Whether rows duplicating existing content would be skipped.
   *
   * @return array
   *   Preview results, which always include:
   *   - 'duplicates': array, labels of the rows that would be skipped.
   *   - 'total': int, the number of rows examined.
   *   The rows that would be created come back under a per-implementation key
   *   ('valid_profiles', 'valid_resources'), because each confirmation form
   *   renders its own content type and holds the concrete service to do it.
   */
  public function previewImport(array $data, $skip_duplicates);

}
