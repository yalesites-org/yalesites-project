<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the grandfather update hook for platform authorization.
 *
 * The ys_beacon_update_10004() hook authorizes Beacon on any site already using
 * it (chat enabled, or the ys_beacon integration turned on), so no live site
 * loses Beacon when the platform gate ships, and leaves other sites off.
 *
 * @group ys_beacon
 */
class BeaconPlatformAuthorizationUpdateTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
  }

  /**
   * Puts a config factory into the container for the update hook.
   *
   * @param \Drupal\Core\Config\Config $beacon
   *   The editable ys_beacon.settings config.
   * @param bool $integrationOn
   *   Whether the ys_integrations.integration_settings:ys_beacon flag is on.
   */
  private function setConfigFactory(Config $beacon, bool $integrationOn): void {
    $integration = $this->createMock(Config::class);
    $integration->method('get')->with('ys_beacon')->willReturn($integrationOn ? 1 : 0);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($beacon);
    $factory->method('get')->with('ys_integrations.integration_settings')->willReturn($integration);

    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);
  }

  /**
   * A site with chat enabled is authorized.
   */
  public function testAuthorizesWhenChatEnabled(): void {
    $beacon = $this->createMock(Config::class);
    $beacon->method('isNew')->willReturn(FALSE);
    $beacon->method('get')->with('enable_chat')->willReturn(TRUE);
    $beacon->expects($this->once())
      ->method('set')
      ->with('platform_authorized', TRUE)
      ->willReturnSelf();
    $beacon->expects($this->once())->method('save')->willReturnSelf();
    $this->setConfigFactory($beacon, FALSE);

    ys_beacon_update_10004();
  }

  /**
   * A site with the integration on but chat off is still authorized.
   */
  public function testAuthorizesWhenIntegrationOnButChatOff(): void {
    $beacon = $this->createMock(Config::class);
    $beacon->method('isNew')->willReturn(FALSE);
    $beacon->method('get')->with('enable_chat')->willReturn(FALSE);
    $beacon->expects($this->once())
      ->method('set')
      ->with('platform_authorized', TRUE)
      ->willReturnSelf();
    $beacon->expects($this->once())->method('save')->willReturnSelf();
    $this->setConfigFactory($beacon, TRUE);

    ys_beacon_update_10004();
  }

  /**
   * A site using neither chat nor the integration is left unauthorized.
   */
  public function testDeauthorizesWhenNeitherInUse(): void {
    $beacon = $this->createMock(Config::class);
    $beacon->method('isNew')->willReturn(FALSE);
    $beacon->method('get')->with('enable_chat')->willReturn(FALSE);
    $beacon->expects($this->once())
      ->method('set')
      ->with('platform_authorized', FALSE)
      ->willReturnSelf();
    $beacon->expects($this->once())->method('save')->willReturnSelf();
    $this->setConfigFactory($beacon, FALSE);

    ys_beacon_update_10004();
  }

  /**
   * Sites with no Beacon settings are left untouched; no config is created.
   */
  public function testLeavesAbsentSettingsUntouched(): void {
    $beacon = $this->createMock(Config::class);
    $beacon->method('isNew')->willReturn(TRUE);
    $beacon->expects($this->never())->method('set');
    $beacon->expects($this->never())->method('save');
    $this->setConfigFactory($beacon, FALSE);

    ys_beacon_update_10004();
  }

}
