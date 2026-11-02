<?php

namespace Drupal\Tests\ys_localist\Unit\Plugin\migrate\process;

use Drupal\Tests\UnitTestCase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_localist\Plugin\migrate\process\SkipPlaceholderPlace;

/**
 * Unit tests for the SkipPlaceholderPlace migrate process plugin.
 *
 * @coversDefaultClass \Drupal\ys_localist\Plugin\migrate\process\SkipPlaceholderPlace
 *
 * @group yalesites
 * @group ys_localist
 */
class SkipPlaceholderPlaceTest extends UnitTestCase {

  /**
   * Runs the plugin on one value.
   *
   * @return array
   *   The transformed value and whether the pipeline was stopped.
   */
  protected function transform(mixed $value): array {
    $plugin = new SkipPlaceholderPlace([], 'skip_placeholder_place', []);
    $result = $plugin->transform(
      $value,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'field_event_place',
    );
    return [$result, $plugin->isPipelineStopped()];
  }

  /**
   * Placeholder and empty location names.
   */
  public static function placeholderCases(): array {
    return [
      ['Other'],
      ['TBD'],
      ['tba'],
      [' N/A '],
      ['n/a'],
      [''],
      ['   '],
      [NULL],
    ];
  }

  /**
   * @covers ::transform
   * @dataProvider placeholderCases
   */
  public function testStopsOnPlaceholders(mixed $value): void {
    [$result, $stopped] = $this->transform($value);
    $this->assertNull($result);
    $this->assertTrue($stopped);
  }

  /**
   * @covers ::transform
   */
  public function testPassesRealNamesThroughTrimmed(): void {
    [$result, $stopped] = $this->transform('  Yale Schwarzman Center ');
    $this->assertSame('Yale Schwarzman Center', $result);
    $this->assertFalse($stopped);

    // A name that merely contains a placeholder word is real.
    [$result, $stopped] = $this->transform('Other Hall');
    $this->assertSame('Other Hall', $result);
    $this->assertFalse($stopped);
  }

}
