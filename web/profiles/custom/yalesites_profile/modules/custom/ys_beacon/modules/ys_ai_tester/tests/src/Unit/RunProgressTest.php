<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\RunProgress;

/**
 * Tests which of a run's questions a resume would ask.
 *
 * Only ::missing() is exercised, matching how RunComparatorTest tests
 * RunComparator: the difference takes already-loaded values, so it needs no
 * database. The queries around it are thin and verified manually.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_ai_tester\RunProgress
 */
class RunProgressTest extends UnitTestCase {

  /**
   * A run that stopped partway asks only the questions it never reached.
   *
   * @covers ::missing
   */
  public function testAsksOnlyTheQuestionsNeverReached(): void {
    $questions = ['q0', 'q1', 'q2', 'q3', 'q4'];

    $missing = RunProgress::missing($questions, [0, 1, 2]);

    $this->assertSame([3 => 'q3', 4 => 'q4'], $missing);
  }

  /**
   * Deltas are preserved, so a resumed answer keeps its position in the run.
   *
   * This is what keeps the run comparable question-for-question with its
   * sibling: re-indexing here would file answers against the wrong questions.
   *
   * @covers ::missing
   */
  public function testKeepsTheOriginalDeltaAsTheKey(): void {
    $questions = ['q0', 'q1', 'q2', 'q3'];

    $missing = RunProgress::missing($questions, [1, 2]);

    $this->assertSame([0 => 'q0', 3 => 'q3'], $missing);
    $this->assertSame([0, 3], array_keys($missing));
  }

  /**
   * A gap in the middle is filled, not just the tail.
   *
   * A batch that skipped one question mid-run leaves a hole rather than a
   * truncation, and that hole is exactly what makes a run non-comparable.
   *
   * @covers ::missing
   */
  public function testFillsGapInTheMiddleOfRun(): void {
    $missing = RunProgress::missing(['q0', 'q1', 'q2'], [0, 2]);

    $this->assertSame([1 => 'q1'], $missing);
  }

  /**
   * A fully answered run has nothing to resume.
   *
   * @covers ::missing
   */
  public function testReportsNothingForCompletedRun(): void {
    $this->assertSame([], RunProgress::missing(['q0', 'q1'], [0, 1]));
  }

  /**
   * A run that never answered anything asks its whole list.
   *
   * @covers ::missing
   */
  public function testAsksEverythingWhenNothingWasStored(): void {
    $this->assertSame([0 => 'q0', 1 => 'q1'], RunProgress::missing(['q0', 'q1'], []));
  }

  /**
   * Duplicate stored deltas do not shift what is considered missing.
   *
   * A batch operation that ran twice writes two rows for one delta - which has
   * happened in production - so the deltas, not the row count, decide.
   *
   * @covers ::missing
   */
  public function testIgnoresDuplicateStoredDeltas(): void {
    $missing = RunProgress::missing(['q0', 'q1', 'q2'], [0, 0, 1, 1, 1]);

    $this->assertSame([2 => 'q2'], $missing);
  }

  /**
   * Deltas arriving as strings from the database still match.
   *
   * @covers ::missing
   */
  public function testMatchesDeltasStoredAsStrings(): void {
    $this->assertSame([2 => 'q2'], RunProgress::missing(['q0', 'q1', 'q2'], ['0', '1']));
  }

}
