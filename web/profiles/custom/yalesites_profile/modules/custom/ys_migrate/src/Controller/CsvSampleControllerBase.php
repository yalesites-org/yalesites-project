<?php

namespace Drupal\ys_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a downloadable skeleton CSV for one of the bulk importers.
 *
 * Each importer supplies its columns, an example row and a file name; the
 * response and the CSV writing are shared so the samples stay alike.
 */
abstract class CsvSampleControllerBase extends ControllerBase {

  /**
   * The CSV validator service.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService
   */
  protected $csvValidator;

  /**
   * Constructs a sample CSV controller.
   *
   * @param \Drupal\ys_migrate\Service\CsvValidatorService $csv_validator
   *   The CSV validator service.
   */
  public function __construct(CsvValidatorService $csv_validator) {
    $this->csvValidator = $csv_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('ys_migrate.csv_validator'));
  }

  /**
   * Returns the sample CSV as a downloadable attachment.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CSV response.
   */
  public function download() {
    $csv = static::buildCsv($this->columns(), $this->exampleRow());

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $this->filename() . '"');

    return $response;
  }

  /**
   * The columns the importer recognises.
   *
   * @return array
   *   Column header labels keyed by normalised header, the shape the
   *   CsvValidatorService getExpected*Columns() methods return.
   */
  abstract protected function columns();

  /**
   * The single example row shown under the header, keyed like a CSV row.
   *
   * @return array
   *   Example cell values keyed by the same machine names as columns().
   */
  abstract protected function exampleRow();

  /**
   * The file name the browser saves the sample as.
   *
   * @return string
   *   The file name.
   */
  abstract protected function filename();

  /**
   * Builds CSV text from a header row and one example row.
   *
   * @param array $columns
   *   Expected columns, keyed by machine name with the header label as the
   *   value (the shape columns() returns).
   * @param array $example_row
   *   Example values keyed by the same machine names as $columns. A column
   *   with no matching key is written as an empty cell.
   *
   * @return string
   *   The CSV file contents, including the header row.
   */
  public static function buildCsv(array $columns, array $example_row) {
    $stream = fopen('php://temp', 'r+');

    fputcsv($stream, array_values($columns));
    fputcsv($stream, array_map(
      static fn ($key) => $example_row[$key] ?? '',
      array_keys($columns)
    ));

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return $csv;
  }

}
