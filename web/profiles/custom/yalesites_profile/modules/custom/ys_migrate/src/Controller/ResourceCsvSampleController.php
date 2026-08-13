<?php

namespace Drupal\ys_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_migrate\Service\CsvValidatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a downloadable skeleton CSV for the Resource bulk importer.
 */
class ResourceCsvSampleController extends ControllerBase {

  /**
   * The CSV validator service.
   *
   * @var \Drupal\ys_migrate\Service\CsvValidatorService
   */
  protected $csvValidator;

  /**
   * Constructs a ResourceCsvSampleController object.
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
    $csv = static::buildCsv($this->csvValidator->getExpectedResourceColumns(), $this->exampleRow());

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="resource-import-sample.csv"');

    return $response;
  }

  /**
   * The single example row shown under the header, keyed like a CSV row.
   *
   * Values are illustrative only: a real http URL for External Source, a
   * date accepted by ResourceImportService::DATE_INPUT_FORMATS, and a
   * Date Format label accepted by ResourceImportService::parseDateFormat().
   *
   * @return array
   *   Example cell values keyed by the same machine names as
   *   CsvValidatorService::EXPECTED_RESOURCE_COLUMNS.
   */
  protected function exampleRow() {
    return [
      'title' => 'Yale Research Symposium Recap',
      'description' => 'Summary of presentations from the annual research symposium.',
      'resource category' => 'Event',
      'audience' => 'Faculty, Staff',
      'custom vocab' => 'Sustainability',
      'resource publication date' => '2026-03-04',
      'date format' => 'Month/Day/Year',
      'tags' => 'Research, Symposium',
      'teaser title' => 'Symposium Recap',
      'teaser text' => 'Highlights from this year\'s research symposium.',
      'external source' => 'https://example.edu/symposium-2026',
      'cas login required' => 'No',
      'pin to beginning of list' => 'No',
    ];
  }

  /**
   * Builds CSV text from a header row and one example row.
   *
   * @param array $columns
   *   Expected columns, keyed by machine name with the header label as the
   *   value (the same shape CsvValidatorService::getExpectedResourceColumns()
   *   returns).
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
