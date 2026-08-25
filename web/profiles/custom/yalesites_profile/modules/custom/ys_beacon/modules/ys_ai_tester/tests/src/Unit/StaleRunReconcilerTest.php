<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_tester\StaleRunReconciler;

/**
 * Tests which abandoned runs get reconciled.
 *
 * Only ::staleIds() is exercised - a pure static decision over already-loaded
 * rows, so it needs no database, matching how RunProgressTest tests
 * RunProgress::missing(). ::reconcile() and ::reconcileOne() are covered by
 * manual verification against a real database, not by this suite.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_ai_tester\StaleRunReconciler
 */
class StaleRunReconcilerTest extends UnitTestCase {

  /**
   * A run silent for longer than the threshold is abandoned.
   *
   * @covers ::staleIds
   */
  public function testReportsRunSilentPastTheThreshold(): void {
    $now = 1_800_000_000;
    $silent_for = StaleRunReconciler::STALE_AFTER_SECONDS + 1;

    $stale = StaleRunReconciler::staleIds(
      [['id' => 7, 'changed' => $now - $silent_for]],
      $now
    );

    $this->assertSame([7], $stale);
  }

  /**
   * A run that wrote a question moments ago is still alive.
   *
   * @covers ::staleIds
   */
  public function testLeavesRecentlyActiveRunAlone(): void {
    $now = 1_800_000_000;

    $stale = StaleRunReconciler::staleIds(
      [['id' => 7, 'changed' => $now - 30]],
      $now
    );

    $this->assertSame([], $stale);
  }

  /**
   * The threshold itself is not yet stale, so the boundary cannot drift.
   *
   * Pinned because an off-by-one here reaps a run that is still writing, which
   * would abandon answers a user is waiting on rather than merely mislabelling
   * a dead run.
   *
   * @covers ::staleIds
   */
  public function testTreatsTheThresholdItselfAsStillAlive(): void {
    $now = 1_800_000_000;

    $stale = StaleRunReconciler::staleIds(
      [['id' => 7, 'changed' => $now - StaleRunReconciler::STALE_AFTER_SECONDS]],
      $now
    );

    $this->assertSame([], $stale);
  }

  /**
   * Live and abandoned runs in one pass are separated, not lumped together.
   *
   * @covers ::staleIds
   */
  public function testSeparatesAbandonedRunsFromLiveOnes(): void {
    $now = 1_800_000_000;
    $past = $now - (StaleRunReconciler::STALE_AFTER_SECONDS * 2);

    $stale = StaleRunReconciler::staleIds(
      [
        ['id' => 1, 'changed' => $past],
        ['id' => 2, 'changed' => $now - 5],
        ['id' => 3, 'changed' => $past],
      ],
      $now
    );

    $this->assertSame([1, 3], $stale);
  }

  /**
   * A run predating the heartbeat column is stale rather than immortal.
   *
   * ::STALE_AFTER_SECONDS is measured from the last write, and a row that never
   * recorded one carries 0. Those are exactly the runs already wedged in
   * 'processing' when this shipped, so they have to be reapable.
   *
   * @covers ::staleIds
   */
  public function testReportsRunThatNeverRecordedProgress(): void {
    $stale = StaleRunReconciler::staleIds(
      [['id' => 16, 'changed' => 0]],
      1_800_000_000
    );

    $this->assertSame([16], $stale);
  }

  /**
   * Nothing processing means nothing to reconcile.
   *
   * @covers ::staleIds
   */
  public function testReportsNothingWhenNoRunsAreProcessing(): void {
    $this->assertSame([], StaleRunReconciler::staleIds([], 1_800_000_000));
  }

}
