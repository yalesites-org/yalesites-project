<?php

namespace Drupal\Tests\ys_alert\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_alert\AlertManager;

/**
 * Tests that the ys_alert.manager service is wired up correctly.
 *
 * @group yalesites
 * @group ys_alert
 */
class AlertManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ys_alert',
  ];

  /**
   * @covers \Drupal\ys_alert\AlertManager::create
   */
  public function testServiceIsInstantiatedFromContainer() {
    $alertManager = $this->container->get('ys_alert.manager');

    // The service resolves to a real AlertManager built via ::create().
    $this->assertInstanceOf(AlertManager::class, $alertManager);
    // With no config saved yet, an alert is not shown by default.
    $this->assertFalse($alertManager->showAlert());
  }

}
