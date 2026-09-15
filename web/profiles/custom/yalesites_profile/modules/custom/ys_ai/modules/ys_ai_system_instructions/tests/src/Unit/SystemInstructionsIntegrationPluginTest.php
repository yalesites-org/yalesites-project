<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\BeaconSupersession;
use Drupal\ys_ai_system_instructions\Plugin\ys_integrations\SystemInstructionsIntegrationPlugin;
use Drupal\ys_ai_system_instructions\Service\SystemInstructionsManagerService;

/**
 * Unit tests for the supersession gate on SystemInstructionsIntegrationPlugin.
 *
 * The legacy system instructions card is fully configured on a site that has
 * moved to Beacon, so without a supersession gate it keeps offering a second
 * instructions editor next to Beacon's own.
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Plugin\ys_integrations\SystemInstructionsIntegrationPlugin
 *
 * @group ys_ai
 * @group yalesites
 */
class SystemInstructionsIntegrationPluginTest extends UnitTestCase {

  /**
   * Builds the plugin over a fully configured site in a supersession state.
   */
  protected function createPlugin(bool $superseded): SystemInstructionsIntegrationPlugin {
    $config = $this->getMockBuilder(ImmutableConfig::class)
      ->disableOriginalConstructor()
      ->getMock();
    $config->method('get')->willReturnMap([
      ['system_instructions_enabled', TRUE],
      ['system_instructions_api_endpoint', 'https://example.com/api'],
      ['system_instructions_web_app_name', 'example-app'],
      ['system_instructions_api_key', 'example-key'],
    ]);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('ys_ai_system_instructions.settings')
      ->willReturn($config);

    $supersession = $this->createMock(BeaconSupersession::class);
    $supersession->method('isSuperseded')->willReturn($superseded);

    $manager = $this->createMock(SystemInstructionsManagerService::class);

    return new SystemInstructionsIntegrationPlugin(
      $config_factory,
      ['label' => 'AI System Instructions', 'description' => 'Manage AI system instructions.'],
      $this->createMock(AccountInterface::class),
      $manager,
      $supersession,
    );
  }

  /**
   * A fully configured integration still reports on as usual.
   *
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOnWhenConfiguredAndNotSuperseded(): void {
    $this->assertTrue($this->createPlugin(FALSE)->isTurnedOn());
  }

  /**
   * The same configured integration reports off once superseded.
   *
   * @covers ::isTurnedOn
   */
  public function testIsTurnedOffWhenSuperseded(): void {
    $this->assertFalse($this->createPlugin(TRUE)->isTurnedOn());
  }

  /**
   * No card is rendered once superseded.
   *
   * @covers ::build
   */
  public function testBuildRendersNothingWhenSuperseded(): void {
    $this->assertSame([], $this->createPlugin(TRUE)->build());
  }

}
