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

/**
 * Tests the "Index now" button state and submit handler on the Beacon form.
 *
 * Covers the decision logic without standing up a full search_api tracked
 * index (no existing test builds one; doing so needs a datasource plus real
 * content). The button's `#disabled` flag is wired to
 * `indexRemainingItems() < 1`, so asserting that helper across the
 * missing/disabled/error/has-items states fully exercises the enable/disable
 * rule. The end-to-end button render and post-batch redirect are verified
 * manually on Lando per the spec.
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
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $form = (new \ReflectionClass(YsBeaconSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'entityTypeManager', $entity_type_manager);
    $this->setProtected($form, 'indexingBatchHelper', $helper ?? $this->createMock(IndexingBatchHelperInterface::class));
    $this->setProtected($form, 'configFactory', $this->getConfigFactoryStub([
      'ys_beacon.settings' => ['search_index_id' => 'ys_beacon'],
    ]));
    $this->setProtected($form, 'messenger', $messenger ?? $this->createMock(MessengerInterface::class));
    $this->setProtected($form, 'stringTranslation', $this->getStringTranslationStub());

    return $form;
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
   * Invokes a protected method on the form.
   */
  private function invoke(YsBeaconSettings $form, string $method, array $args = []): mixed {
    $reflection = new \ReflectionMethod(YsBeaconSettings::class, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($form, $args);
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
      $index->method('getTrackerInstance')->willReturn($tracker);
    }
    return $index;
  }

  /**
   * No index means nothing to index, so the button stays disabled.
   *
   * @covers ::indexRemainingItems
   */
  public function testRemainingItemsIsZeroWhenIndexMissing(): void {
    $form = $this->buildForm(NULL);
    $this->assertSame(0, $this->invoke($form, 'indexRemainingItems'));
  }

  /**
   * A disabled index reports zero remaining without touching the tracker.
   *
   * @covers ::indexRemainingItems
   */
  public function testRemainingItemsIsZeroWhenIndexDisabled(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $index->expects($this->never())->method('getTrackerInstance');
    $form = $this->buildForm($index);
    $this->assertSame(0, $this->invoke($form, 'indexRemainingItems'));
  }

  /**
   * An enabled index returns the tracker's remaining count.
   *
   * @covers ::indexRemainingItems
   */
  public function testRemainingItemsReturnsTrackerCount(): void {
    $form = $this->buildForm($this->indexMock(TRUE, 7));
    $this->assertSame(7, $this->invoke($form, 'indexRemainingItems'));
  }

  /**
   * A tracker error degrades to zero rather than crashing the form.
   *
   * @covers ::indexRemainingItems
   */
  public function testRemainingItemsIsZeroOnTrackerError(): void {
    $form = $this->buildForm($this->indexMock(TRUE, NULL));
    $this->assertSame(0, $this->invoke($form, 'indexRemainingItems'));
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
