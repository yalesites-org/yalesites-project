<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterBatch;

/**
 * Tests the run status a finished batch records.
 *
 * The bug this covers: a hundred-question run where one question hit a
 * transient upstream error was recorded as 'failed', identically to a run where
 * every single question failed. There was no way to tell a run that was 99
 * percent successful from a collapsed one, so large runs read as broken and
 * their results got discarded.
 *
 * @coversDefaultClass \Drupal\ys_ai_tester\AiTesterBatch
 *
 * @group ys_beacon
 */
class AiTesterRunStatusTest extends UnitTestCase {

  /**
   * @covers ::runStatus
   * @dataProvider provideStatusCases
   */
  public function testRunStatus(bool $success, int $error_count, int $processed, string $expected): void {
    $this->assertSame($expected, AiTesterBatch::runStatus($success, $error_count, $processed));
  }

  /**
   * Batch success flag, errored questions, processed questions, run status.
   */
  public static function provideStatusCases(): array {
    return [
      'every question answered' => [TRUE, 0, 100, 'complete'],
      // The reported case: one transient 500 out of a hundred.
      'one question of a hundred failed' => [TRUE, 1, 100, 'partial'],
      'most questions failed but not all' => [TRUE, 99, 100, 'partial'],
      // The boundary either side of 'failed': one survivor is still partial.
      'boundary, one short of every question failing' => [TRUE, 9, 10, 'partial'],
      // Reserved for a genuine collapse - a bad key, a missing assistant, an
      // upstream that is down for the whole run.
      'every question failed' => [TRUE, 100, 100, 'failed'],
      'single question run that failed' => [TRUE, 1, 1, 'failed'],
      'single question run that passed' => [TRUE, 0, 1, 'complete'],
      // The batch itself aborted: whatever the per-question tally says, the run
      // did not finish, so it must not read as complete or partial.
      'batch aborted with no errors recorded' => [FALSE, 0, 50, 'failed'],
      'batch aborted with errors recorded' => [FALSE, 2, 50, 'failed'],
      // Defensive: finished() with nothing processed is not a success.
      'nothing processed' => [TRUE, 0, 0, 'failed'],
    ];
  }

  /**
   * Every status the method can return fits the storage column.
   *
   * The ys_ai_tester_run.status column is varchar(32), which is why this change
   * needs no update hook. Asserted over what ::runStatus() actually returns
   * rather than over a hardcoded list, so a future status too long for the
   * column fails here instead of being silently truncated on write.
   *
   * @covers ::runStatus
   */
  public function testEveryStatusFitsTheStorageColumn(): void {
    foreach ([[TRUE, 0, 10], [TRUE, 1, 10], [TRUE, 10, 10], [FALSE, 0, 10], [TRUE, 0, 0]] as $case) {
      $status = AiTesterBatch::runStatus(...$case);
      $this->assertLessThanOrEqual(32, strlen($status), "Status '{$status}' does not fit varchar(32).");
    }
  }

}
