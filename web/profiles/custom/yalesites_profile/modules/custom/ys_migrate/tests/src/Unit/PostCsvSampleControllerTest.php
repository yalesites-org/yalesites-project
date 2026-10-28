<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Controller\PostCsvSampleController;
use Drupal\ys_migrate\Service\CsvValidatorService;

/**
 * Unit tests for PostCsvSampleController.
 *
 * BuildCsv() itself is shared with the resource sample and covered by
 * ResourceCsvSampleControllerTest.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Controller\PostCsvSampleController
 * @group ys_migrate
 * @group yalesites
 */
class PostCsvSampleControllerTest extends UnitTestCase {

  /**
   * Download() streams a CSV response with the post sample's filename.
   *
   * @covers ::download
   */
  public function testDownloadReturnsCsvAttachmentResponse() {
    $csvValidator = $this->createMock(CsvValidatorService::class);
    $csvValidator->method('getExpectedPostColumns')->willReturn([
      'title' => 'Title',
      'publish date' => 'Publish Date',
    ]);

    $controller = new PostCsvSampleController($csvValidator);
    $response = $controller->download();

    $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    $this->assertStringContainsString('post-import-sample.csv', $response->headers->get('Content-Disposition'));

    $rows = array_map('str_getcsv', explode("\n", trim($response->getContent())));
    $this->assertSame(['Title', 'Publish Date'], $rows[0]);
  }

  /**
   * Download() writes exactly the columns CsvValidatorService expects, filled.
   *
   * Guards against the hardcoded example row drifting from
   * CsvValidatorService::EXPECTED_POST_COLUMNS. External Source is the one
   * column deliberately left empty: a post with one redirects visitors away,
   * so an example with both it and a Body would teach the wrong thing.
   *
   * @covers ::download
   */
  public function testDownloadExampleRowFillsEveryExpectedColumn() {
    $csvValidator = new CsvValidatorService();
    $controller = new PostCsvSampleController($csvValidator);

    $response = $controller->download();
    // Parse as a real CSV stream rather than splitting on newlines, so a quoted
    // Body cell holding a line break would still read as one cell.
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $response->getContent());
    rewind($stream);
    $header = fgetcsv($stream);
    $example = fgetcsv($stream);
    fclose($stream);

    $expected = array_values($csvValidator->getExpectedPostColumns());
    $this->assertSame($expected, $header);
    $this->assertCount(count($expected), $example, 'Example row has one cell per expected column.');

    foreach ($header as $index => $label) {
      if ($label === 'External Source') {
        $this->assertSame('', $example[$index]);
        continue;
      }
      $this->assertNotSame('', $example[$index], "{$label} should have an example value.");
    }
  }

}
