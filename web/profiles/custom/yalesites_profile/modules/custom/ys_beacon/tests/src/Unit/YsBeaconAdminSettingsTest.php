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
   *
   * @return array
   *   The form under test and the editable config mock (keyed 'form','config').
   */
  private function buildForm(BeaconIndexManager $index_manager, string $previous_index_name): array {
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnSelf();
    $config->method('save')->willReturnSelf();
    $config->method('get')->willReturnCallback(fn (string $key) => $key === 'azure_index_name' ? $previous_index_name : NULL);

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

    $built = $this->buildForm($index_manager, '');

    $form_state = new FormState();
    $form_state->setValue('azure_index_name', 'other-site-live');
    $form_state->setValue('read_only', 1);

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

}
