<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\AiTesterBatch;

/**
 * Tests the status a resumed run is recorded with.
 *
 * Separate from the per-batch ::runStatus() because a resume's batch only
 * processed the questions that were missing, so its own tallies describe a
 * fraction of the run. These cases are the reason the two cannot share one
 * function.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_ai_tester\AiTesterBatch
 */
class WholeRunStatusTest extends UnitTestCase {

  /**
   * A resume that answered the last gap completes the whole run.
   *
   * @covers ::wholeRunStatus
   */
  public function testCompletesWhenEveryQuestionIsAnswered(): void {
    $this->assertSame('complete', AiTesterBatch::wholeRunStatus(TRUE, 160, 160, 0));
  }

  /**
   * Answering only part of the gap leaves the run partial, not complete.
   *
   * The case ::runStatus() would get wrong: a resume batch that succeeded at
   * every question it was given still leaves the run unfinished, so success
   * plus a clean tally must not read as complete.
   *
   * @covers ::wholeRunStatus
   */
  public function testStaysPartialWhenQuestionsRemainUnanswered(): void {
    $this->assertSame('partial', AiTesterBatch::wholeRunStatus(TRUE, 160, 140, 0));
  }

  /**
   * A resume interrupted again leaves the run partial and resumable.
   *
   * @covers ::wholeRunStatus
   */
  public function testStaysPartialWhenTheResumeBatchDidNotFinish(): void {
    $this->assertSame('partial', AiTesterBatch::wholeRunStatus(FALSE, 160, 150, 0));
  }

  /**
   * Every question asked but some failing is partial, not complete.
   *
   * @covers ::wholeRunStatus
   */
  public function testStaysPartialWhenSomeAnswersFailed(): void {
    $this->assertSame('partial', AiTesterBatch::wholeRunStatus(TRUE, 160, 160, 3));
  }

  /**
   * A run where nothing was ever answered is failed.
   *
   * @covers ::wholeRunStatus
   */
  public function testFailsWhenNothingWasAttempted(): void {
    $this->assertSame('failed', AiTesterBatch::wholeRunStatus(TRUE, 160, 0, 0));
  }

  /**
   * A run where every attempt errored is failed, not partial.
   *
   * @covers ::wholeRunStatus
   */
  public function testFailsWhenEveryAttemptErrored(): void {
    $this->assertSame('failed', AiTesterBatch::wholeRunStatus(TRUE, 160, 40, 40));
  }

  /**
   * More attempts than expected still completes rather than failing.
   *
   * Duplicate deltas are counted distinctly, but an expected count that has
   * drifted below the stored deltas must not turn a finished run into a
   * failure.
   *
   * @covers ::wholeRunStatus
   */
  public function testCompletesWhenAttemptsExceedTheExpectedCount(): void {
    $this->assertSame('complete', AiTesterBatch::wholeRunStatus(TRUE, 2, 3, 0));
  }

}
