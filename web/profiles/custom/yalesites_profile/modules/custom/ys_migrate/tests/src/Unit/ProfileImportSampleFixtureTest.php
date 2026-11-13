<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Service\CsvValidatorService;

/**
 * Unit tests for the Profile CSV importer's sample fixture.
 *
 * The tests/fixtures/profile-import-sample.csv file is uploaded by hand
 * through the Profile CSV import form when the importer is tested, so no
 * production code references it. This test is that reference: it keeps the
 * file's purpose discoverable, and it fails if the importer's expected columns
 * drift away from the sample so the sample cannot silently go stale.
 *
 * @group ys_migrate
 * @group yalesites
 */
class ProfileImportSampleFixtureTest extends UnitTestCase {

  /**
   * The sample CSV matches the columns the profile importer expects.
   */
  public function testSampleMatchesExpectedColumns() {
    $path = __DIR__ . '/../../fixtures/profile-import-sample.csv';
    $this->assertFileExists($path, 'The profile import sample fixture is missing.');

    // Read with fgetcsv rather than splitting lines: a quoted cell may legally
    // contain a newline, and a line-based read would mis-split it and report a
    // misleading column-count failure.
    $handle = fopen($path, 'r');
    $rows = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
      $rows[] = $row;
    }
    fclose($handle);

    $expected = array_values(CsvValidatorService::EXPECTED_COLUMNS);
    $this->assertSame($expected, array_shift($rows), 'The sample header row no longer matches CsvValidatorService::EXPECTED_COLUMNS.');

    $this->assertNotEmpty($rows, 'The sample should carry at least one example row.');
    foreach ($rows as $index => $row) {
      $this->assertCount(count($expected), $row, sprintf('Example row %d does not have one cell per expected column.', $index + 1));
    }
  }

}
