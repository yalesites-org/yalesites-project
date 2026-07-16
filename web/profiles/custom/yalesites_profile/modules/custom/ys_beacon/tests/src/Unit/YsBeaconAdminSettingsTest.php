<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\YsBeaconAdminSettings;
use Drupal\ys_beacon\Service\BeaconIndexManager;

/**
 * Tests the read-only handling of the Beacon administration settings form.
 *
 * A read-only site borrows another site's collection, so entering the shared
 * index name and checking Read-only must point at that collection WITHOUT
 * provisioning it: provision() would create the index when missing, rebuild the
 * tracker, and claim content was queued - all writes a borrowing site must
 * never perform. A writable site keeps provisioning as before.
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
   * Sets a protected/inherited property on an object via reflection.
   */
  private function setProtected(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue($object, $value);
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

}
