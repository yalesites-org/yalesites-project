<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterBatch;

/**
 * Tests the status a resumed or abandoned run is recorded with.
 *
 * Separate from ::runStatus() because a resume's batch only processed the
 * questions that were missing, so its own tallies describe a fraction of the
 * run. The provider table below is the reason the two cannot share one
 * function: the same tallies map to different statuses depending on whether
 * anything is still outstanding.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\AiTesterBatch
 *
 * @group ys_beacon
 */
class WholeRunStatusTest extends UnitTestCase {

  /**
   * @covers ::wholeRunStatus
   * @dataProvider provideWholeRunCases
   */
  public function testWholeRunStatus(
    bool $success,
    int $remaining,
    int $attempted,
    int $error_count,
    string $expected,
  ): void {
    $this->assertSame(
      $expected,
      AiTesterBatch::wholeRunStatus($success, $remaining, $attempted, $error_count)
    );
  }

  /**
   * Batch finished, outstanding, attempted, errored, resulting status.
   *
   * The second row is the case ::runStatus() gets wrong, and the reason this
   * function exists: a resume batch that answered every question it was handed
   * still leaves the run unfinished, so a clean tally must not read as
   * complete.
   */
  public static function provideWholeRunCases(): array {
    return [
      'nothing outstanding, none errored, batch finished' => [TRUE, 0, 160, 0, 'complete'],
      'questions still outstanding despite a clean batch' => [TRUE, 20, 140, 0, 'partial'],
      'resume interrupted again' => [FALSE, 10, 150, 0, 'partial'],
      'every question asked but some errored' => [TRUE, 0, 160, 3, 'partial'],
      'a single good answer among errors' => [TRUE, 0, 4, 3, 'partial'],
      'nothing attempted at all' => [TRUE, 160, 0, 0, 'failed'],
      'every attempt errored' => [TRUE, 120, 40, 40, 'failed'],
      'errors cannot exceed attempts, but are handled if they do' => [TRUE, 0, 2, 3, 'failed'],
      // A finished batch with nothing outstanding and no errors is the only
      // route to 'complete'; dropping any one of the three loses it.
      'finished flag is required for complete' => [FALSE, 0, 160, 0, 'partial'],
    ];
  }

  /**
   * @covers ::abandonedRunStatus
   * @dataProvider provideAbandonedCases
   */
  public function testAbandonedRunStatus(
    int $attempted,
    int $error_count,
    string $expected,
  ): void {
    $this->assertSame(
      $expected,
      AiTesterBatch::abandonedRunStatus($attempted, $error_count)
    );
  }

  /**
   * Attempted, errored, resulting status.
   *
   * 'complete' is absent by design: an abandoned batch did not finish, so no
   * tally can earn it. That is what lets the reconciler decide without reading
   * the run's question list at all.
   */
  public static function provideAbandonedCases(): array {
    return [
      'usable answers survive as partial' => [150, 0, 'partial'],
      'a single answer is still partial' => [1, 0, 'partial'],
      'some errored, some answered' => [40, 12, 'partial'],
      'nothing attempted' => [0, 0, 'failed'],
      'every attempt errored' => [40, 40, 'failed'],
    ];
  }

}
