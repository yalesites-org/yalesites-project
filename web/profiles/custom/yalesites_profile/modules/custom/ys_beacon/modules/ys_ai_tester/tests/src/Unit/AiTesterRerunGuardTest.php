<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Form\AiTesterRerunForm;

/**
 * Tests the server-side double-fire guard for re-running a stored run.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Form\AiTesterRerunForm
 *
 * @group ys_beacon
 */
class AiTesterRerunGuardTest extends UnitTestCase {

  /**
   * @covers ::isBlocked
   * @dataProvider provideGuardCases
   */
  public function testIsBlocked(?string $source_status, int $in_flight, ?string $expected): void {
    $this->assertSame($expected, AiTesterRerunForm::isBlocked($source_status, $in_flight));
  }

  /**
   * Source status + in-flight rerun count and the resolved block reason.
   */
  public static function provideGuardCases(): array {
    return [
      'complete run, none in flight, allowed' => ['complete', 0, NULL],
      'failed run may be re-run' => ['failed', 0, NULL],
      'source still processing is blocked' => ['processing', 0, 'source_processing'],
      'existing in-flight rerun is blocked' => ['complete', 1, 'already_running'],
      'multiple in-flight reruns blocked' => ['complete', 3, 'already_running'],
      'processing source takes precedence over in-flight' => ['processing', 2, 'source_processing'],
    ];
  }

}
