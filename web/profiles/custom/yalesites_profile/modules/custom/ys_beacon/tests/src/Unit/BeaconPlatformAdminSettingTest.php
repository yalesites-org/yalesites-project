<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\PlatformAdminSetting\BeaconPlatformAdminSetting;

/**
 * Tests the Beacon platform admin setting plugin.
 *
 * The toggle that authorizes Beacon for the site, contributed to the Platform
 * Admin Settings page: its checkbox reflects the stored flag and its submit
 * saves the flag back to ys_beacon.settings.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\PlatformAdminSetting\BeaconPlatformAdminSetting
 */
class BeaconPlatformAdminSettingTest extends UnitTestCase {

  /**
   * Builds the plugin against a read-only config factory stub.
   */
  private function pluginForRead(array $settings): BeaconPlatformAdminSetting {
    $plugin = new BeaconPlatformAdminSetting(
      [],
      'ys_beacon',
      [],
      $this->getConfigFactoryStub(['ys_beacon.settings' => $settings]),
      $this->createMock(AccountInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * The checkbox default value reflects the stored authorization flag.
   *
   * @covers ::buildSettings
   */
  public function testCheckboxDefaultReflectsFlag(): void {
    $form_state = new FormState();

    $on = $this->pluginForRead(['platform_authorized' => TRUE])
      ->buildSettings([], $form_state);
    $this->assertTrue((bool) $on['platform_authorized']['#default_value']);
    $this->assertSame('checkbox', $on['platform_authorized']['#type']);

    $off = $this->pluginForRead([])->buildSettings([], $form_state);
    $this->assertFalse((bool) $off['platform_authorized']['#default_value']);
  }

  /**
   * Submitting saves the checkbox value to ys_beacon.settings.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSavesFlag(): void {
    $config = $this->createMock(Config::class);
    $config->expects($this->once())
      ->method('set')
      ->with('platform_authorized', TRUE)
      ->willReturnSelf();
    $config->expects($this->once())->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_beacon.settings')->willReturn($config);

    $plugin = new BeaconPlatformAdminSetting(
      [],
      'ys_beacon',
      [],
      $factory,
      $this->createMock(AccountInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());

    $form_state = new FormState();
    // The section is rendered with #tree under the plugin id, so the value is
    // nested under 'ys_beacon'.
    $form_state->setValue(['ys_beacon', 'platform_authorized'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

}
