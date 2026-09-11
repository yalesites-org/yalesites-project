<?php

namespace Drupal\Tests\ys_localist\Unit\Plugin\migrate\process;

use Drupal\Tests\UnitTestCase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_localist\Plugin\migrate\process\ExtractLocalistGroups;

/**
 * Unit tests for the ExtractLocalistGroups migrate process plugin.
 *
 * @coversDefaultClass \Drupal\ys_localist\Plugin\migrate\process\ExtractLocalistGroups
 *
 * @group yalesites
 * @group ys_localist
 */
class ExtractLocalistGroupsTest extends UnitTestCase {

  /**
   * Builds the plugin under test.
   */
  protected function createPlugin(): ExtractLocalistGroups {
    return new ExtractLocalistGroups([], 'extract_localist_groups', []);
  }

  /**
   * @covers ::transform
   */
  public function testTransformExtractsIdsFromEachGroup() {
    $plugin = $this->createPlugin();

    $result = $plugin->transform(
      [
        ['id' => 5, 'name' => 'Arts'],
        ['id' => 9, 'name' => 'Sciences'],
      ],
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_event_groups'
    );

    $this->assertSame([5, 9], $result);
  }

  /**
   * @covers ::transform
   */
  public function testTransformReturnsEmptyArrayForEmptyValue() {
    $plugin = $this->createPlugin();

    $result = $plugin->transform(
      [],
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_event_groups'
    );

    $this->assertSame([], $result);
  }

  /**
   * @covers ::multiple
   */
  public function testMultipleReturnsTrue() {
    $plugin = $this->createPlugin();
    $this->assertTrue($plugin->multiple());
  }

}
