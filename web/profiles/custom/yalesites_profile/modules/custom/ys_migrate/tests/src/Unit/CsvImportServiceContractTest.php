<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Service\CsvImportServiceInterface;
use Drupal\ys_migrate\Service\ProfileImportService;
use Drupal\ys_migrate\Service\ResourceImportService;

/**
 * Tests that the CSV import services stay bound to the shared contract.
 *
 * CsvImportBatch resolves an import service from a string id and calls it, so
 * a service that loses the interface only fails at import time. This fails at
 * test time instead.
 *
 * The list below is deliberately explicit, which means it does NOT catch a
 * brand-new importer that forgets both the base class and this list -- adding
 * one is a manual step. What it does catch is either existing service being
 * unhooked from the base. The enforcement itself is covered in
 * CsvImportBatchTest, alongside the rest of processChunk().
 *
 * @group ys_migrate
 * @group yalesites
 */
class CsvImportServiceContractTest extends UnitTestCase {

  /**
   * Every CSV import service implements the shared contract.
   *
   * @dataProvider importServiceClasses
   */
  public function testImportServicesImplementTheContract($class) {
    $this->assertContains(
      CsvImportServiceInterface::class,
      class_implements($class),
      $class . ' must implement CsvImportServiceInterface.'
    );
  }

  /**
   * The module's CSV import service classes.
   *
   * @return array
   *   Each case holds one import service class name.
   */
  public static function importServiceClasses(): array {
    return [
      'profile' => [ProfileImportService::class],
      'resource' => [ResourceImportService::class],
    ];
  }

}
