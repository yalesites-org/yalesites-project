<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Controller\ResourceCsvSampleController;
use Drupal\ys_migrate\Service\CsvValidatorService;

/**
 * Unit tests for ResourceCsvSampleController.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Controller\ResourceCsvSampleController
 * @group ys_migrate
 * @group yalesites
 */
class ResourceCsvSampleControllerTest extends UnitTestCase {

  /**
   * BuildCsv() writes the column labels as the header row, in order.
   *
   * @covers ::buildCsv
   */
  public function testBuildCsvWritesHeaderRowInColumnOrder() {
    $columns = ['title' => 'Title', 'audience' => 'Audience', 'tags' => 'Tags'];
    $example = ['title' => 'Example', 'audience' => 'Faculty', 'tags' => 'Research'];

    $csv = ResourceCsvSampleController::buildCsv($columns, $example);
    $rows = array_map('str_getcsv', explode("\n", trim($csv)));

    $this->assertSame(['Title', 'Audience', 'Tags'], $rows[0]);
  }

  /**
   * BuildCsv() writes the example row values in the same column order.
   *
   * @covers ::buildCsv
   */
  public function testBuildCsvWritesExampleRowInColumnOrder() {
    $columns = ['title' => 'Title', 'audience' => 'Audience', 'tags' => 'Tags'];
    $example = ['title' => 'Example Resource', 'audience' => 'Faculty', 'tags' => 'Research'];

    $csv = ResourceCsvSampleController::buildCsv($columns, $example);
    $rows = array_map('str_getcsv', explode("\n", trim($csv)));

    $this->assertSame(['Example Resource', 'Faculty', 'Research'], $rows[1]);
  }

  /**
   * BuildCsv() correctly quotes an example value containing a comma.
   *
   * @covers ::buildCsv
   */
  public function testBuildCsvQuotesValuesContainingCommas() {
    $columns = ['title' => 'Title', 'audience' => 'Audience'];
    $example = ['title' => 'Example Resource', 'audience' => 'Faculty, Staff'];

    $csv = ResourceCsvSampleController::buildCsv($columns, $example);
    $rows = array_map('str_getcsv', explode("\n", trim($csv)));

    // A naive implementation might just implode(',', ...) and split the
    // multi-value cell into two columns; str_getcsv() only recovers the
    // intended single value if the writer actually quoted it.
    $this->assertSame(['Example Resource', 'Faculty, Staff'], $rows[1]);
  }

  /**
   * BuildCsv() writes an empty cell for a column missing from the example.
   *
   * @covers ::buildCsv
   */
  public function testBuildCsvWritesEmptyCellForMissingExampleValue() {
    $columns = ['title' => 'Title', 'description' => 'Description'];
    $example = ['title' => 'Example Resource'];

    $csv = ResourceCsvSampleController::buildCsv($columns, $example);
    $rows = array_map('str_getcsv', explode("\n", trim($csv)));

    $this->assertSame(['Example Resource', ''], $rows[1]);
  }

  /**
   * Download() streams a CSV response with an attachment filename.
   *
   * @covers ::download
   */
  public function testDownloadReturnsCsvAttachmentResponse() {
    $csvValidator = $this->createMock(CsvValidatorService::class);
    $csvValidator->method('getExpectedResourceColumns')->willReturn([
      'title' => 'Title',
      'audience' => 'Audience',
    ]);

    $controller = new ResourceCsvSampleController($csvValidator);
    $response = $controller->download();

    $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    $this->assertStringContainsString('resource-import-sample.csv', $response->headers->get('Content-Disposition'));

    $rows = array_map('str_getcsv', explode("\n", trim($response->getContent())));
    $this->assertSame(['Title', 'Audience'], $rows[0]);
  }

  /**
   * Download() only ever writes columns CsvValidatorService actually expects.
   *
   * Guards against the controller's own hardcoded example row silently
   * drifting out of sync with CsvValidatorService::EXPECTED_RESOURCE_COLUMNS
   * (e.g. after a column is renamed or removed there).
   *
   * @covers ::download
   */
  public function testDownloadExampleRowHasNoColumnsOutsideExpected() {
    $csvValidator = new CsvValidatorService();
    $controller = new ResourceCsvSampleController($csvValidator);

    $response = $controller->download();
    $rows = array_map('str_getcsv', explode("\n", trim($response->getContent())));

    $expected = array_values($csvValidator->getExpectedResourceColumns());
    $this->assertSame($expected, $rows[0]);
    $this->assertCount(count($expected), $rows[1], 'Example row has one cell per expected column.');
  }

}
