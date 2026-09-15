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
  public function testIsBlocked(
    ?string $source_status,
    int $in_flight,
    bool $backend_available,
    ?string $expected,
  ): void {
    $this->assertSame(
      $expected,
      AiTesterRerunForm::isBlocked($source_status, $in_flight, $backend_available)
    );
  }

  /**
   * Source status, in-flight rerun count, backend availability, block reason.
   *
   * A run whose assistant is gone is refused rather than attempted: a stored
   * legacy run stays viewable and comparable after the legacy option becomes
   * unavailable, but re-running it is refused gracefully rather than erroring
   * part-way through a batch.
   */
  public static function provideGuardCases(): array {
    return [
      'complete run, none in flight, allowed' => ['complete', 0, TRUE, NULL],
      'failed run may be re-run' => ['failed', 0, TRUE, NULL],
      'source still processing is blocked' => ['processing', 0, TRUE, 'source_processing'],
      'existing in-flight rerun is blocked' => ['complete', 1, TRUE, 'already_running'],
      'multiple in-flight reruns blocked' => ['complete', 3, TRUE, 'already_running'],
      'processing source takes precedence over in-flight' => ['processing', 2, TRUE, 'source_processing'],
      'unavailable backend blocks a clean rerun' => ['complete', 0, FALSE, 'backend_unavailable'],
      'unavailable backend outranks a processing source' => ['processing', 0, FALSE, 'backend_unavailable'],
      'unavailable backend outranks an in-flight rerun' => ['complete', 2, FALSE, 'backend_unavailable'],
    ];
  }

}
