<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use Drupal\search_api\Utility\IndexingBatchHelperInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\YsBeaconAdminSettings;
use Drupal\ys_beacon\Service\BeaconIndexManager;

/**
 * Tests the Beacon administration settings form.
 *
 * Covers the read-only handling of the vector index connection (a borrow points
 * at a shared collection WITHOUT provisioning it), the site guardrail
 * supplement save that moved here from the site settings form, and the
 * indexing controls (re-index all / mirrored index now) the form now hosts
 * through the shared BeaconIndexingControlsTrait.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Form\YsBeaconAdminSettings
 */
class YsBeaconAdminSettingsTest extends UnitTestCase {

  /**
   * Builds the form with a config mock and a given index manager.
   *
   * @param \Drupal\ys_beacon\Service\BeaconIndexManager $index_manager
   *   The index manager (with provision() expectations set by the caller).
   * @param string $previous_index_name
   *   The azure_index_name currently stored, to drive the change detection.
   * @param bool $previous_read_only
   *   The read_only flag currently stored, to drive the read-only transition.
   *
   * @return array
   *   The form under test and the editable config mock (keyed 'form','config').
   */
  private function buildForm(
    BeaconIndexManager $index_manager,
    string $previous_index_name,
    bool $previous_read_only = FALSE,
  ): array {
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();
    $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'azure_index_name' => $previous_index_name,
      'read_only' => $previous_read_only,
      default => NULL,
    });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);
    $factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $form = (new \ReflectionClass(YsBeaconAdminSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'configFactory', $factory);
    $this->setProtected($form, 'indexManager', $index_manager);
    $this->setProtected($form, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtected($form, 'stringTranslation', $this->getStringTranslationStub());

    return ['form' => $form, 'config' => $config];
  }

  /**
   * Builds the admin form wired for the shared indexing controls.
   *
   * @param \Drupal\search_api\IndexInterface|null $index
   *   The index that storage->load('ys_beacon') should return, or NULL.
   * @param \Drupal\search_api\Utility\IndexingBatchHelperInterface|null $helper
   *   The batch helper, or NULL for a do-nothing stub.
   * @param \Drupal\Core\Messenger\MessengerInterface|null $messenger
   *   The messenger, or NULL for a do-nothing stub.
   *
   * @return \Drupal\ys_beacon\Form\YsBeaconAdminSettings
   *   The form with its protected indexing dependencies populated.
   */
  private function buildIndexingForm(?IndexInterface $index, ?IndexingBatchHelperInterface $helper = NULL, ?MessengerInterface $messenger = NULL): YsBeaconAdminSettings {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $form = (new \ReflectionClass(YsBeaconAdminSettings::class))->newInstanceWithoutConstructor();
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
   * Invokes a protected method via reflection.
   */
  private function invoke(object $object, string $method, array $args = []): mixed {
    $reflection = new \ReflectionMethod($object, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invokeArgs($object, $args);
  }

  /**
   * A read-only borrow persists the index name without provisioning it.
   *
   * @covers ::submitForm
   */
  public function testReadOnlyBorrowSkipsProvisioning(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');
    // The borrow is persisted straight into the real config, read-only on.
    $index_manager->expects($this->once())
      ->method('propagateConnection')
      ->with('other-site-live', TRUE);

    $built = $this->buildForm($index_manager, '');

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'other-site-live');
    $form_state->setValue('read_only', 1);

    $form_array = [];
    $built['form']->submitForm($form_array, $form_state);
  }

  /**
   * Toggling read-only on the current index drives the flag, no provisioning.
   *
   * A save that only flips Read-only (the index name is unchanged) must still
   * propagate the flag onto the real config, and must never provision.
   *
   * @covers ::submitForm
   */
  public function testReadOnlyToggleOnSameIndexPropagates(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('provision');
    $index_manager->expects($this->once())
      ->method('propagateConnection')
      ->with('my-site-live', TRUE);

    // Index name already stored; only Read-only changes.
    $built = $this->buildForm($index_manager, 'my-site-live');

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'my-site-live');
    $form_state->setValue('read_only', 1);

    $form_array = [];
    $built['form']->submitForm($form_array, $form_state);
  }

  /**
   * Switching a read-only borrow to writable on the same index re-provisions.
   *
   * The tracker of a borrowing site was never seeded, so flipping it writable
   * must still provision() (verify the index + rebuild the tracker), even when
   * the index name is unchanged.
   *
   * @covers ::submitForm
   */
  public function testReadOnlyToWritableSameIndexProvisions(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())->method('provision')->with('shared-idx');

    // Previously borrowing "shared-idx" read-only; now writable, same name.
    $built = $this->buildForm($index_manager, 'shared-idx', TRUE);

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'shared-idx');
    $form_state->setValue('read_only', 0);

    $form_array = [];
    $built['form']->submitForm($form_array, $form_state);
  }

  /**
   * A writable site still provisions the index when the name changes.
   *
   * @covers ::submitForm
   */
  public function testWritableSiteProvisionsIndex(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())
      ->method('provision')
      ->with('my-site-live');

    $built = $this->buildForm($index_manager, '');

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'my-site-live');
    $form_state->setValue('read_only', 0);

    $form_array = [];
    $built['form']->submitForm($form_array, $form_state);
  }

  /**
   * The whole-index query toggle is persisted from the admin form value.
   *
   * @covers ::submitForm
   */
  public function testQueryEntireIndexIsPersisted(): void {
    $captured = [];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      // Same writable index name, so submit propagates without provisioning.
      'azure_index_name' => 'my-site-live',
      'read_only' => FALSE,
      default => NULL,
    });
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $config) {
      $captured[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    $index_manager = $this->createMock(BeaconIndexManager::class);

    $form = (new \ReflectionClass(YsBeaconAdminSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'configFactory', $factory);
    $this->setProtected($form, 'indexManager', $index_manager);
    $this->setProtected($form, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtected($form, 'stringTranslation', $this->getStringTranslationStub());

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'my-site-live');
    $form_state->setValue('read_only', 0);
    $form_state->setValue('query_entire_index', 1);

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $this->assertArrayHasKey('query_entire_index', $captured);
    $this->assertTrue($captured['query_entire_index']);
  }

  /**
   * The site guardrail supplement is saved from the admin form value.
   *
   * The guardrail section moved from the site settings form to here, so its
   * supplement is now written to ys_beacon.settings by this form's submit.
   *
   * @covers ::submitForm
   */
  public function testGuardrailSupplementIsSaved(): void {
    $captured = [];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      // Same writable index name, so submit propagates without provisioning.
      'azure_index_name' => 'my-site-live',
      'read_only' => FALSE,
      default => NULL,
    });
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $config) {
      $captured[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    $index_manager = $this->createMock(BeaconIndexManager::class);

    $form = (new \ReflectionClass(YsBeaconAdminSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'configFactory', $factory);
    $this->setProtected($form, 'indexManager', $index_manager);
    $this->setProtected($form, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtected($form, 'stringTranslation', $this->getStringTranslationStub());

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'my-site-live');
    $form_state->setValue('read_only', 0);
    $form_state->setValue('guardrail_supplement', 'Never mention competitors.');

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $this->assertArrayHasKey('guardrail_supplement', $captured);
    $this->assertSame('Never mention competitors.', $captured['guardrail_supplement']);
  }

  /**
   * The admin form hosts both indexing controls when the index is writable.
   *
   * @covers ::buildIndexingControls
   */
  public function testBuildIndexingControlsIncludesReindex(): void {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn(3);
    $tracker->method('getIndexedItemsCount')->willReturn(1);
    $tracker->method('getTotalItemsCount')->willReturn(4);
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->method('getTrackerInstance')->willReturn($tracker);

    $form = $this->buildIndexingForm($index);
    $element = $this->invoke($form, 'buildIndexingControls', [TRUE]);

    $this->assertArrayHasKey('reindex', $element);
    $this->assertArrayHasKey('index_now', $element);
    $this->assertSame('Re-index all content', (string) $element['reindex']['#value']);
  }

  /**
   * A read-only borrow hides the admin indexing controls behind the notice.
   *
   * @covers ::buildIndexingControls
   */
  public function testBuildIndexingControlsReadOnlyHidesControls(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn(TRUE);

    $form = $this->buildIndexingForm($index);
    $element = $this->invoke($form, 'buildIndexingControls', [TRUE]);

    $this->assertArrayNotHasKey('reindex', $element);
    $this->assertArrayNotHasKey('index_now', $element);
    $this->assertArrayHasKey('status', $element);
  }

  /**
   * The "Re-index all content" button on the admin form rebuilds the tracker.
   *
   * @covers ::reindexAll
   */
  public function testReindexAllRebuildsTracker(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->expects($this->once())->method('rebuildTracker');
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $messenger->expects($this->never())->method('addWarning');

    $form = $this->buildIndexingForm($index, NULL, $messenger);
    $form_array = [];
    $form->reindexAll($form_array, $this->createMock(FormStateInterface::class));
  }

  /**
   * The mirrored "Index now" on the admin form runs the Beacon index batch.
   *
   * Identical behavior to the site settings form: only the index is passed to
   * createBatch(), so Search API uses the index's own cron_limit.
   *
   * @covers ::indexNow
   */
  public function testIndexNowMirroredRunsBatch(): void {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn(12);
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->method('getTrackerInstance')->willReturn($tracker);
    $helper = $this->createMock(IndexingBatchHelperInterface::class);
    $helper->expects($this->once())
      ->method('createBatch')
      ->with($this->identicalTo($index));

    $form = $this->buildIndexingForm($index, $helper);
    $form_array = [];
    $form->indexNow($form_array, $this->createMock(FormStateInterface::class));
  }

}
