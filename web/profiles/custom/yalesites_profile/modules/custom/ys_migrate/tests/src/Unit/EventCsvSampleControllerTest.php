<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Controller\EventCsvSampleController;
use Drupal\ys_migrate\Service\CsvValidatorService;

/**
 * Unit tests for EventCsvSampleController.
 *
 * BuildCsv() itself is shared with the resource sample and covered by
 * ResourceCsvSampleControllerTest.
 *
 * @coversDefaultClass \Drupal\ys_migrate\Controller\EventCsvSampleController
 * @group ys_migrate
 * @group yalesites
 */
class EventCsvSampleControllerTest extends UnitTestCase {

  /**
   * Download() streams a CSV response with the event sample's filename.
   *
   * @covers ::download
   */
  public function testDownloadReturnsCsvAttachmentResponse() {
    $csvValidator = $this->createMock(CsvValidatorService::class);
    $csvValidator->method('getExpectedEventColumns')->willReturn([
      'title' => 'Title',
      'start' => 'Start',
    ]);

    $controller = new EventCsvSampleController($csvValidator);
    $response = $controller->download();

    $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    $this->assertStringContainsString('event-import-sample.csv', $response->headers->get('Content-Disposition'));

    $rows = array_map('str_getcsv', explode("\n", trim($response->getContent())));
    $this->assertSame(['Title', 'Start'], $rows[0]);
  }

  /**
   * Download() writes exactly the columns CsvValidatorService expects, filled.
   *
   * Guards against the hardcoded example row drifting from
   * CsvValidatorService::EXPECTED_EVENT_COLUMNS. All Day and Stream URL are
   * deliberately left empty: the example is a timed, in-person event.
   *
   * @covers ::download
   */
  public function testDownloadExampleRowFillsEveryExpectedColumn() {
    $csvValidator = new CsvValidatorService();
    $controller = new EventCsvSampleController($csvValidator);

    $response = $controller->download();
    // Parse as a real CSV stream rather than splitting on newlines, so the
    // quoted Location Details cell's line break still reads as one cell.
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $response->getContent());
    rewind($stream);
    $header = fgetcsv($stream);
    $example = fgetcsv($stream);
    fclose($stream);

    $expected = array_values($csvValidator->getExpectedEventColumns());
    $this->assertSame($expected, $header);
    $this->assertCount(count($expected), $example, 'Example row has one cell per expected column.');

    foreach ($header as $index => $label) {
      if (in_array($label, ['All Day', 'Stream URL'], TRUE)) {
        $this->assertSame('', $example[$index]);
        continue;
      }
      $this->assertNotSame('', $example[$index], "{$label} should have an example value.");
    }
  }

}
