<?php

namespace Drupal\Tests\ys_localist\Unit\Plugin\migrate\process;

use Drupal\Tests\UnitTestCase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_localist\Plugin\migrate\process\ExtractLocalistFilter;

/**
 * Unit tests for the ExtractLocalistFilter migrate process plugin.
 *
 * @coversDefaultClass \Drupal\ys_localist\Plugin\migrate\process\ExtractLocalistFilter
 *
 * @group yalesites
 * @group ys_localist
 */
class ExtractLocalistFilterTest extends UnitTestCase {

  /**
   * Builds the plugin under test with the given filter configuration.
   */
  protected function createPlugin(string $filter): ExtractLocalistFilter {
    return new ExtractLocalistFilter(['filter' => $filter], 'extract_localist_filter', []);
  }

  /**
   * @covers ::transform
   */
  public function testTransformExtractsIdsForConfiguredFilter() {
    $plugin = $this->createPlugin('event_types');

    $result = $plugin->transform(
      [
        'event_types' => [
          ['id' => 11, 'name' => 'Lecture'],
          ['id' => 22, 'name' => 'Workshop'],
        ],
        'event_audience' => [
          ['id' => 33, 'name' => 'Alumni'],
        ],
      ],
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_localist_event_type'
    );

    $this->assertSame([11, 22], $result);
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsEmptyArrayWhenFilterKeyMissing() {
    $plugin = $this->createPlugin('event_audience');

    $result = $plugin->transform(
      ['event_types' => [['id' => 11]]],
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_event_audience'
    );

    $this->assertSame([], $result);
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsEmptyArrayForNonArrayValue() {
    $plugin = $this->createPlugin('event_types');

    $result = $plugin->transform(
      NULL,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_localist_event_type'
    );

    $this->assertSame([], $result);
  }

  /**
   * @covers ::multiple
   */
  public function testMultipleReturnsTrue() {
    $plugin = $this->createPlugin('event_types');
    $this->assertTrue($plugin->multiple());
  }

}
