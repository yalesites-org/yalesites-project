<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the update hook that forces the floating button icon to "sparkles".
 *
 * Exercises ys_beacon_update_10002() against a mocked config factory: the icon
 * is no longer site-configurable, so any site that has Beacon settings is moved
 * onto fa-sparkles, while sites with no settings are left untouched.
 *
 * @group ys_beacon
 */
class FloatingButtonIconUpdateTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
  }

  /**
   * Puts a config factory returning the given settings into the container.
   */
  private function setConfigFactory(Config $config): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')
      ->with('ys_beacon.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);
  }

  /**
   * Existing Beacon settings are forced onto fa-sparkles and saved.
   *
   * The hook does not branch on the current value, so this single path covers
   * both a legacy "chat" selection and settings that never stored the icon.
   */
  public function testForcesSparklesWhenSettingsExist(): void {
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->expects($this->once())
      ->method('set')
      ->with('floating_button_icon', 'fa-sparkles')
      ->willReturnSelf();
    $config->expects($this->once())->method('save')->willReturnSelf();
    $this->setConfigFactory($config);

    ys_beacon_update_10002();
  }

  /**
   * Sites with no Beacon settings are left untouched; no config is created.
   */
  public function testLeavesAbsentSettingsUntouched(): void {
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn(TRUE);
    $config->expects($this->never())->method('set');
    $config->expects($this->never())->method('save');
    $this->setConfigFactory($config);

    ys_beacon_update_10002();
  }

}
