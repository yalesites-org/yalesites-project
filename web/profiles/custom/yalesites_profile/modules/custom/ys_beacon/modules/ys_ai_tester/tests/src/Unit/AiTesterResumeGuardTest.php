<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\Form\AiTesterResumeForm;

/**
 * Tests the server-side guard for finishing an interrupted run in place.
 *
 * The mirror of AiTesterRerunGuardTest, and load-bearing for the same reason:
 * this decision is what keeps a resume from queueing questions into a run that
 * is already being written, which would put duplicate deltas into the very run
 * the resume exists to repair.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\Form\AiTesterResumeForm
 *
 * @group ys_beacon
 */
class AiTesterResumeGuardTest extends UnitTestCase {

  /**
   * @covers ::isBlocked
   * @dataProvider provideGuardCases
   */
  public function testIsBlocked(
    ?string $status,
    int $missing_count,
    bool $backend_available,
    ?string $expected,
  ): void {
    $this->assertSame(
      $expected,
      AiTesterResumeForm::isBlocked($status, $missing_count, $backend_available)
    );
  }

  /**
   * Run status, outstanding question count, backend availability, block reason.
   *
   * The precedence cases are the point of the table rather than filler: the
   * reasons are decided by the order of the checks, so reordering them silently
   * changes which message a user sees, and an unavailable assistant has to
   * outrank everything - there is nothing left to answer with.
   */
  public static function provideGuardCases(): array {
    return [
      'failed run with outstanding questions may resume' => ['failed', 40, TRUE, NULL],
      'partial run with outstanding questions may resume' => ['partial', 1, TRUE, NULL],
      // A run marked complete can still carry a gap: question_count and the
      // stored deltas are independent, so the status is not the authority.
      'complete run with a gap may still resume' => ['complete', 2, TRUE, NULL],
      'a live run is left to its own batch' => ['processing', 40, TRUE, 'still_processing'],
      'nothing outstanding is refused' => ['complete', 0, TRUE, 'nothing_missing'],
      'nothing outstanding on a failed run is refused' => ['failed', 0, TRUE, 'nothing_missing'],
      'processing outranks nothing outstanding' => ['processing', 0, TRUE, 'still_processing'],
      'unavailable backend blocks an otherwise valid resume' => ['failed', 40, FALSE, 'backend_unavailable'],
      'unavailable backend outranks a processing run' => ['processing', 40, FALSE, 'backend_unavailable'],
      'unavailable backend outranks nothing outstanding' => ['complete', 0, FALSE, 'backend_unavailable'],
      'an unknown status with a gap may resume' => [NULL, 3, TRUE, NULL],
    ];
  }

}
