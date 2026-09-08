<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Tracker\TrackerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconIndexStatus;

/**
 * Tests the shared reader for the Beacon index's state and its wording.
 *
 * These assertions previously lived against the settings form and, in a second
 * copy, against the platform-admin plugin. Both surfaces now delegate here, so
 * the missing / disabled / read-only / tracker-error states are asserted once
 * against the thing that decides them.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconIndexStatus
 */
class BeaconIndexStatusTest extends UnitTestCase {

  /**
   * Builds the service over a mocked index storage.
   *
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The index that load()/loadOverrideFree() should return, or NULL.
   * @param string $configured_id
   *   The value of ys_beacon.settings:search_index_id.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexStatus
   *   The service under test.
   */
  private function build(?IndexInterface $index, string $configured_id = 'ys_beacon'): BeaconIndexStatus {
    // ConfigEntityStorageInterface, not EntityStorageInterface: only the config
    // entity storage declares loadOverrideFree(), which this service uses for
    // write-side decisions.
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->willReturn($index);
    $storage->method('loadOverrideFree')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $status = new BeaconIndexStatus($entity_type_manager, $this->getConfigFactoryStub([
      'ys_beacon.settings' => ['search_index_id' => $configured_id],
    ]));
    $status->setStringTranslation($this->getStringTranslationStub());

    return $status;
  }

  /**
   * Builds an index mock with the given status and tracker behaviour.
   *
   * @param bool $enabled
   *   The value status() returns.
   * @param int|null $remaining
   *   Remaining item count the tracker reports, or NULL to make the tracker
   *   throw, simulating a tracker that cannot be read.
   *
   * @return \Drupal\search_api\IndexInterface
   *   The index mock.
   */
  private function indexMock(bool $enabled, ?int $remaining): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn($enabled);
    if ($remaining === NULL) {
      $index->method('getTrackerInstance')->willThrowException(new SearchApiException('No tracker.'));
      return $index;
    }
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn($remaining);
    $tracker->method('getIndexedItemsCount')->willReturn(0);
    $tracker->method('getTotalItemsCount')->willReturn($remaining);
    $index->method('getTrackerInstance')->willReturn($tracker);

    return $index;
  }

  /**
   * The configured index id is used, falling back to the platform default.
   *
   * @covers ::indexId
   */
  public function testIndexIdFallsBackToDefault(): void {
    $this->assertSame('other_index', $this->build(NULL, 'other_index')->indexId());
    $this->assertSame('ys_beacon', $this->build(NULL, '')->indexId());
  }

  /**
   * A missing index loads as NULL rather than a non-index value.
   *
   * @covers ::load
   * @covers ::loadOverrideFree
   */
  public function testMissingIndexLoadsAsNull(): void {
    $status = $this->build(NULL);
    $this->assertNull($status->load());
    $this->assertNull($status->loadOverrideFree());
  }

  /**
   * No index means nothing to index, so the control stays disabled.
   *
   * @covers ::remainingItems
   */
  public function testRemainingItemsIsZeroWhenIndexMissing(): void {
    $this->assertSame(0, $this->build(NULL)->remainingItems());
  }

  /**
   * A disabled index reports zero remaining without touching the tracker.
   *
   * @covers ::remainingItems
   */
  public function testRemainingItemsIsZeroWhenIndexDisabled(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $index->expects($this->never())->method('getTrackerInstance');
    $this->assertSame(0, $this->build($index)->remainingItems());
  }

  /**
   * An enabled index returns the tracker's remaining count.
   *
   * @covers ::remainingItems
   */
  public function testRemainingItemsReturnsTrackerCount(): void {
    $this->assertSame(7, $this->build($this->indexMock(TRUE, 7))->remainingItems());
  }

  /**
   * A tracker error degrades to zero rather than crashing the caller.
   *
   * @covers ::remainingItems
   */
  public function testRemainingItemsIsZeroOnTrackerError(): void {
    $this->assertSame(0, $this->build($this->indexMock(TRUE, NULL))->remainingItems());
  }

  /**
   * An unreadable tracker counts as zero tracked items, so seeding still runs.
   *
   * @covers ::trackedItems
   */
  public function testTrackedItemsIsZeroOnTrackerError(): void {
    $index = $this->indexMock(TRUE, NULL);
    $this->assertSame(0, $this->build($index)->trackedItems($index));
  }

  /**
   * A readable tracker reports its total item count.
   *
   * @covers ::trackedItems
   */
  public function testTrackedItemsReturnsTotal(): void {
    $index = $this->indexMock(TRUE, 4);
    $this->assertSame(4, $this->build($index)->trackedItems($index));
  }

  /**
   * A missing index is not read-only: there is no borrowed collection.
   *
   * @covers ::isReadOnly
   */
  public function testIsReadOnlyIsFalseWhenIndexMissing(): void {
    $this->assertFalse($this->build(NULL)->isReadOnly());
  }

  /**
   * A borrowed collection reports read-only.
   *
   * @covers ::isReadOnly
   */
  public function testIsReadOnlyIsTrueForBorrowedIndex(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn(TRUE);
    $this->assertTrue($this->build($index)->isReadOnly());
  }

  /**
   * An enabled index summarises its item counts.
   *
   * @covers ::summary
   * @covers ::statusMarkup
   */
  public function testSummaryShowsCountsWhenEnabled(): void {
    $status = $this->build($this->indexMock(TRUE, 5));
    $this->assertStringContainsString('items indexed', $status->summary());
    $this->assertStringContainsString('items indexed', $status->statusMarkup());
  }

  /**
   * A disabled index reports the disabled status rather than item counts.
   *
   * @covers ::summary
   * @covers ::statusMarkup
   */
  public function testSummaryShowsDisabledStatus(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $status = $this->build($index);
    $this->assertStringContainsString('disabled', strtolower($status->summary()));
    $this->assertStringContainsString('disabled', strtolower($status->statusMarkup()));
  }

  /**
   * A tracker that cannot be read says so instead of reporting a false count.
   *
   * @covers ::summary
   */
  public function testSummaryReportsUnavailableOnTrackerError(): void {
    $this->assertStringContainsString(
      'unavailable',
      strtolower($this->build($this->indexMock(TRUE, NULL))->summary())
    );
  }

  /**
   * A read-only borrow shows the shared-collection notice, not item counts.
   *
   * @covers ::statusMarkup
   * @covers ::readOnlyNotice
   */
  public function testStatusMarkupShowsReadOnlyNotice(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn(TRUE);
    $status = $this->build($index);
    $this->assertStringContainsString('read-only', strtolower($status->statusMarkup()));
    $this->assertStringContainsString('read-only', strtolower((string) $status->readOnlyNotice()));
  }

}
