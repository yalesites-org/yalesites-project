<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\YsBeaconSettings;
use Drupal\ys_beacon\Service\BeaconIndexManager;

/**
 * Tests the site Beacon settings form's create-if-missing provisioning.
 *
 * Enabling the chat widget must (re)create the index this site is configured to
 * use whenever it does not actually exist - first enable, a failed retry, a
 * deleted index, or a newly chosen name - while leaving an existing index and a
 * read-only borrow untouched.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Form\YsBeaconSettings
 */
class YsBeaconSettingsTest extends UnitTestCase {

  /**
   * A read-only borrow never provisions the collection it reads.
   *
   * @covers ::configuredIndexMissing
   */
  public function testReadOnlyBorrowIsNeverMissing(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('indexExists');
    $form = $this->makeForm($index_manager, ['read_only' => TRUE, 'azure_index_name' => 'shared-idx']);

    $this->assertFalse($this->invoke($form, 'configuredIndexMissing'));
  }

  /**
   * A site with no index name assigned yet needs provisioning.
   *
   * @covers ::configuredIndexMissing
   */
  public function testUnassignedIndexIsMissing(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->never())->method('indexExists');
    $form = $this->makeForm($index_manager, ['read_only' => FALSE, 'azure_index_name' => '']);

    $this->assertTrue($this->invoke($form, 'configuredIndexMissing'));
  }

  /**
   * An assigned index that exists in Azure needs no provisioning.
   *
   * @covers ::configuredIndexMissing
   */
  public function testExistingIndexIsNotMissing(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(TRUE);
    $form = $this->makeForm($index_manager, ['read_only' => FALSE, 'azure_index_name' => 'my-index']);

    $this->assertFalse($this->invoke($form, 'configuredIndexMissing'));
  }

  /**
   * An assigned index deleted from Azure is missing and must be provisioned.
   *
   * @covers ::configuredIndexMissing
   */
  public function testDeletedIndexIsMissing(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())->method('indexExists')->with('my-index')->willReturn(FALSE);
    $form = $this->makeForm($index_manager, ['read_only' => FALSE, 'azure_index_name' => 'my-index']);

    $this->assertTrue($this->invoke($form, 'configuredIndexMissing'));
  }

  /**
   * An unreachable Azure endpoint neither blocks the save nor forces a retry.
   *
   * @covers ::configuredIndexMissing
   */
  public function testUnreachableAzureIsTreatedAsNotMissing(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('indexExists')->willThrowException(new \RuntimeException('unreachable'));
    $form = $this->makeForm($index_manager, ['read_only' => FALSE, 'azure_index_name' => 'my-index']);

    $this->assertFalse($this->invoke($form, 'configuredIndexMissing'));
  }

  /**
   * Provisioning targets the configured index name, not the per-site default.
   *
   * @covers ::provisionIndex
   */
  public function testProvisionIndexUsesConfiguredName(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->expects($this->once())->method('provision')->with('special-idx')->willReturn('special-idx');
    $form = $this->makeForm($index_manager, ['read_only' => FALSE, 'azure_index_name' => 'special-idx']);

    $this->invoke($form, 'provisionIndex');
  }

  /**
   * With no name assigned, provisioning falls back to the per-site default.
   *
   * @covers ::provisionIndex
   */
  public function testProvisionIndexFallsBackToDefault(): void {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    // A NULL argument makes BeaconIndexManager::provision() use the default.
    $index_manager->expects($this->once())->method('provision')->with(NULL)->willReturn('site-default');
    $form = $this->makeForm($index_manager, ['read_only' => FALSE, 'azure_index_name' => '']);

    $this->invoke($form, 'provisionIndex');
  }

  /**
   * Builds the form with a config mock and index manager, sans constructor.
   *
   * @param \Drupal\ys_beacon\Service\BeaconIndexManager $index_manager
   *   The index manager double.
   * @param array $settings
   *   The ys_beacon.settings values keyed by name (read_only,
   *   azure_index_name).
   *
   * @return \Drupal\ys_beacon\Form\YsBeaconSettings
   *   The form under test.
   */
  private function makeForm(BeaconIndexManager $index_manager, array $settings): YsBeaconSettings {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    $form = (new \ReflectionClass(YsBeaconSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'configFactory', $factory);
    $this->setProtected($form, 'indexManager', $index_manager);
    $this->setProtected($form, 'messenger', $this->createMock(MessengerInterface::class));
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
  private function invoke(object $object, string $method): mixed {
    $reflection = new \ReflectionMethod($object, $method);
    $reflection->setAccessible(TRUE);
    return $reflection->invoke($object);
  }

}
