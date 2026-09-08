<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Tracker\TrackerInterface;
use Drupal\search_api\Utility\IndexingBatchHelperInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\YsBeaconSettings;
use Drupal\ys_beacon\Service\BeaconIndexStatus;
use Drupal\ys_beacon\Service\PdfTextIndexer;

/**
 * Tests the "Index now" button state and submit handler on the Beacon form.
 *
 * Covers the submit handlers' decision logic without standing up a full
 * search_api tracked index (no existing test builds one; doing so needs a
 * datasource plus real content). The reads these handlers guard on - the
 * remaining-item count and the status wording that also drives the button's
 * `#disabled` flag - belong to BeaconIndexStatus and are covered by
 * BeaconIndexStatusTest. The end-to-end button render and post-batch redirect
 * are verified manually on Lando per the spec.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Form\YsBeaconSettings
 */
class IndexNowFormTest extends UnitTestCase {

  /**
   * Builds the form with mocked dependencies and a given loaded index.
   *
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The index that storage->load('ys_beacon') should return, or NULL.
   * @param \Drupal\search_api\Utility\IndexingBatchHelperInterface|null $helper
   *   The batch helper, or NULL for a do-nothing stub.
   * @param \Drupal\Core\Messenger\MessengerInterface|null $messenger
   *   The messenger, or NULL for a do-nothing stub.
   *
   * @return \Drupal\ys_beacon\Form\YsBeaconSettings
   *   The form with its protected dependencies populated.
   */
  private function buildForm(?IndexInterface $index, ?IndexingBatchHelperInterface $helper = NULL, ?MessengerInterface $messenger = NULL): YsBeaconSettings {
    $form = (new \ReflectionClass(YsBeaconSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'indexStatus', $this->buildIndexStatus($index));
    $this->setProtected($form, 'indexingBatchHelper', $helper ?? $this->createMock(IndexingBatchHelperInterface::class));
    // No document is waiting on extraction, so the handlers under test do not
    // reach batch_set(); the sweep itself is covered by
    // PdfTextExtractionTriggerTest.
    $pdfTextIndexer = $this->createMock(PdfTextIndexer::class);
    $pdfTextIndexer->method('pendingMediaIds')->willReturn([]);
    $this->setProtected($form, 'pdfTextIndexer', $pdfTextIndexer);
    $this->setProtected($form, 'messenger', $messenger ?? $this->createMock(MessengerInterface::class));
    $this->setProtected($form, 'stringTranslation', $this->getStringTranslationStub());

    return $form;
  }

  /**
   * Builds a real index status reader over a mocked index storage.
   *
   * The real service is used rather than a mock so these tests still exercise
   * the load-and-guard logic the handlers depend on; only the storage beneath
   * it is faked.
   *
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The index that storage->load('ys_beacon') should return, or NULL.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexStatus
   *   The index status reader.
   */
  private function buildIndexStatus(?IndexInterface $index): BeaconIndexStatus {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $status = new BeaconIndexStatus($entity_type_manager, $this->getConfigFactoryStub([
      'ys_beacon.settings' => ['search_index_id' => 'ys_beacon'],
    ]));
    $status->setStringTranslation($this->getStringTranslationStub());

    return $status;
  }

  /**
   * Sets a protected/inherited property on an object via reflection.
   */
  private function setProtected(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue($object, $value);
  }

  /**
   * Builds an index mock with the given status and tracker behaviour.
   *
   * @param bool $enabled
   *   The value status() returns.
   * @param int|null $remaining
   *   Remaining item count the tracker reports, or NULL to make the tracker
   *   throw (simulating an unavailable tracker).
   */
  private function indexMock(bool $enabled, ?int $remaining): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn($enabled);
    if ($remaining === NULL) {
      $index->method('getTrackerInstance')->willThrowException(new SearchApiException('No tracker.'));
    }
    else {
      $tracker = $this->createMock(TrackerInterface::class);
      $tracker->method('getRemainingItemsCount')->willReturn($remaining);
      // Stubbed as ints so indexStatusSummary()'s "@indexed of @total"
      // placeholders never receive NULL (deprecated) when a caller renders the
      // status alongside the remaining-items count.
      $tracker->method('getIndexedItemsCount')->willReturn(0);
      $tracker->method('getTotalItemsCount')->willReturn($remaining);
      $index->method('getTrackerInstance')->willReturn($tracker);
    }
    return $index;
  }

  /**
   * Submitting runs the batch for the Beacon index with index defaults.
   *
   * Only the index is passed to createBatch(), so Search API uses the index's
   * own cron_limit and indexes all remaining items.
   *
   * @covers ::indexNow
   */
  public function testIndexNowRunsBatchWhenEnabled(): void {
    $index = $this->indexMock(TRUE, 12);
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->expects($this->once())
      ->method('createBatch')
      ->with($this->identicalTo($index));
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');

    $form = $this->buildForm($index, $helper, $messenger);
    $form_array = [];
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * An enabled index with nothing queued never starts a batch.
   *
   * Guards the stale-page / cron-drained-queue race: the button's #disabled
   * state is render-time only, so the handler must re-check server-side.
   *
   * @covers ::indexNow
   */
  public function testIndexNowSkipsWhenNothingRemaining(): void {
    $index = $this->indexMock(TRUE, 0);
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->expects($this->never())->method('createBatch');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->never())->method('addWarning');
    $messenger->expects($this->once())->method('addStatus');

    $form = $this->buildForm($index, $helper, $messenger);
    $form_array = [];
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * A disabled index warns the user and never starts a batch.
   *
   * @covers ::indexNow
   */
  public function testIndexNowWarnsWhenIndexDisabled(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->expects($this->never())->method('createBatch');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $form = $this->buildForm($index, $helper, $messenger);
    $form_array = [];
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * A missing index warns the user and never starts a batch.
   *
   * @covers ::indexNow
   */
  public function testIndexNowWarnsWhenIndexMissing(): void {
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->expects($this->never())->method('createBatch');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $form = $this->buildForm(NULL, $helper, $messenger);
    $form_array = [];
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * Re-index all content rebuilds the tracker so existing content is queued.
   *
   * Uses rebuildTracker(), not reindex(): reindex() only re-flags items already
   * in the tracker and cannot populate one that was never seeded, which is the
   * "0 of 0 pages indexed" defect in issue #1383.
   *
   * @covers ::reindexAll
   */
  public function testReindexAllRebuildsTrackerWhenEnabled(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->expects($this->once())->method('rebuildTracker');
    $index->expects($this->never())->method('reindex');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $form = $this->buildForm($index, NULL, $messenger);
    $form_array = [];
    $form->reindexAll($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * Re-index all content on a disabled index warns and never rebuilds.
   *
   * @covers ::reindexAll
   */
  public function testReindexAllWarnsWhenDisabled(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $index->expects($this->never())->method('rebuildTracker');
    $index->expects($this->never())->method('reindex');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');
    $messenger->expects($this->never())->method('addStatus');

    $form = $this->buildForm($index, NULL, $messenger);
    $form_array = [];
    $form->reindexAll($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * A read-only index blocks re-index: it warns and never rebuilds the tracker.
   *
   * A borrowing site must never write to the shared collection, so the local
   * "Re-index all content" control is a no-op guarded server-side.
   *
   * @covers ::reindexAll
   */
  public function testReindexAllBlockedWhenReadOnly(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn(TRUE);
    // Enabled, so without the read-only guard it would rebuild the tracker;
    // the guard must short-circuit before that.
    $index->method('status')->willReturn(TRUE);
    $index->expects($this->never())->method('rebuildTracker');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');
    $messenger->expects($this->never())->method('addStatus');

    $form = $this->buildForm($index, NULL, $messenger);
    $form_array = [];
    $form->reindexAll($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * A read-only index blocks "Index now": it warns and never starts a batch.
   *
   * @covers ::indexNow
   */
  public function testIndexNowBlockedWhenReadOnly(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn(TRUE);
    // Enabled with items queued, so without the read-only guard it would start
    // a batch; the guard must short-circuit before that.
    $index->method('status')->willReturn(TRUE);
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn(5);
    $index->method('getTrackerInstance')->willReturn($tracker);
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->expects($this->never())->method('createBatch');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $form = $this->buildForm($index, $helper, $messenger);
    $form_array = [];
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * A locked/failed batch surfaces a warning instead of a fatal error.
   *
   * @covers ::indexNow
   */
  public function testIndexNowWarnsOnSearchApiException(): void {
    $index = $this->indexMock(TRUE, 5);
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->method('createBatch')->willThrowException(new SearchApiException('Locked.'));
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addWarning');

    $form = $this->buildForm($index, $helper, $messenger);
    $form_array = [];
    // Must not throw: the handler catches SearchApiException.
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

}
