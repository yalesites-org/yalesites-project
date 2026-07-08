<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Plugin\ys_integrations\BeaconIntegrationPlugin;
use Drupal\ys_beacon\Service\SystemInstructionsStorage;

/**
 * Tests the Beacon integration plugin.
 *
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\ys_integrations\BeaconIntegrationPlugin
 *
 * @group ys_beacon
 */
class BeaconIntegrationPluginTest extends UnitTestCase {

  const PLUGIN_DEFINITION = [
    'id' => 'ys_beacon',
    'label' => 'Beacon (AI Chat)',
    'description' => 'Beacon description.',
  ];

  /**
   * Builds the plugin with a config factory stub and mocked dependencies.
   *
   * @param array $beacon_settings
   *   Values to return for ys_beacon.settings.
   *
   * @return \Drupal\ys_beacon\Plugin\ys_integrations\BeaconIntegrationPlugin
   *   The plugin under test.
   */
  protected function buildPlugin(array $beacon_settings): BeaconIntegrationPlugin {
    $config_factory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => $beacon_settings,
    ]);
    return new BeaconIntegrationPlugin(
      $config_factory,
      self::PLUGIN_DEFINITION,
      $this->createMock(AccountInterface::class),
      $this->createMock(SystemInstructionsStorage::class),
      $this->createMock(DateFormatterInterface::class),
      new BeaconAuthorization($config_factory),
    );
  }

  /**
   * The card is actionable only once the platform authorizes Beacon.
   *
   * The card drives the Configure / Manage Instructions actions; until a
   * platform admin authorizes Beacon for the site it must show "not
   * configured", independent of the site admin's chat/index settings.
   *
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnReflectsAuthorization(): void {
    $this->assertFalse($this->buildPlugin([])->isTurnedOn());
    $this->assertFalse($this->buildPlugin(['platform_authorized' => FALSE])->isTurnedOn());
    // The chat/index settings do not make the card actionable on their own.
    $this->assertFalse($this->buildPlugin(['enable_chat' => TRUE, 'azure_index_name' => 'site-live'])->isTurnedOn());
    $this->assertTrue($this->buildPlugin(['platform_authorized' => TRUE])->isTurnedOn());
  }

}
