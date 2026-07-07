<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
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
    );
  }

  /**
   * The Beacon card is always actionable, regardless of chat/index config.
   *
   * Admins reach Configure / Manage Instructions from the card to enable chat
   * and set the index name, so the card must never gate itself off.
   *
   * @covers ::isTurnedOn
   */
  public function testIsAlwaysTurnedOn(): void {
    $this->assertTrue($this->buildPlugin([])->isTurnedOn());
    $this->assertTrue($this->buildPlugin(['enable_chat' => FALSE, 'azure_index_name' => ''])->isTurnedOn());
    $this->assertTrue($this->buildPlugin(['enable_chat' => TRUE, 'azure_index_name' => 'site-live'])->isTurnedOn());
  }

}
