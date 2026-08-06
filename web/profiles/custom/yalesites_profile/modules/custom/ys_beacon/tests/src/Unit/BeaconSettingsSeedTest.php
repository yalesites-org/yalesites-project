<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the fresh-install seed of ys_beacon.settings.
 *
 * The settings object is config_ignored, so it is deliberately absent from
 * config/sync. That matters on the install path a first-time site actually
 * takes: ys_beacon is listed in core.extension, so config:import is what
 * installs it, and a module installed mid-import takes its default config from
 * the sync storage rather than from its own config/install directory
 * (ConfigInstaller::installDefaultConfig() branches on isSyncing()). Nothing
 * named ys_beacon.* exists in sync, so without an explicit seed the settings
 * object would never be created at all.
 *
 * _ys_beacon_seed_settings() reads the shipped file directly to close that gap.
 * The extension list is pointed at the real module directory here, so these
 * assertions run against the actual shipped defaults rather than a fixture.
 *
 * @group ys_beacon
 */
class BeaconSettingsSeedTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/ys_beacon.install';
  }

  /**
   * The module directory holding the shipped config/install file.
   *
   * @return string
   *   The absolute path to the ys_beacon module root.
   */
  private function moduleRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The shipped default settings, parsed from the real config/install file.
   *
   * @return array
   *   The shipped defaults.
   */
  private function shippedDefaults(): array {
    return Yaml::parseFile($this->moduleRoot() . '/config/install/ys_beacon.settings.yml');
  }

  /**
   * Puts a config factory and a module extension list into the container.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The config object getEditable() should return.
   */
  private function setContainer(Config $config): void {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')
      ->with('ys_beacon.settings')
      ->willReturn($config);

    // Points at the real module root, so the seed reads the shipped file.
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('getPath')->with('ys_beacon')->willReturn($this->moduleRoot());

    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    $container->set('extension.list.module', $list);
    \Drupal::setContainer($container);
  }

  /**
   * An absent settings object is created from the shipped defaults.
   */
  public function testSeedsShippedDefaultsWhenAbsent(): void {
    $captured = NULL;
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn(TRUE);
    $config->expects($this->once())
      ->method('setData')
      ->with($this->callback(function (array $data) use (&$captured): bool {
        $captured = $data;
        return TRUE;
      }))
      ->willReturnSelf();
    $config->expects($this->once())->method('save')->willReturnSelf();
    $this->setContainer($config);

    _ys_beacon_seed_settings();

    $this->assertSame($this->shippedDefaults(), $captured, 'The shipped defaults are written verbatim.');

    // Both of these ship on and are read without a fallback, so a missing
    // settings object silently inverts them: streaming turns off, and the AI
    // metadata fields disappear from every content and media form.
    $this->assertTrue($captured['streaming'], 'Streaming ships on.');
    $this->assertTrue($captured['enable_metadata_fields'], 'Metadata fields ship on.');

    // Seeding defaults must never switch a site on.
    $this->assertFalse($captured['platform_authorized'], 'Beacon ships unauthorized.');
    $this->assertFalse($captured['enable_chat'], 'Beacon chat ships off.');
  }

  /**
   * An existing configuration is left completely untouched.
   */
  public function testDoesNotClobberExistingSettings(): void {
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn(FALSE);
    $config->expects($this->never())->method('setData');
    $config->expects($this->never())->method('save');
    $this->setContainer($config);

    _ys_beacon_seed_settings();
  }

  /**
   * The shipped file and the config schema describe the same keys.
   *
   * The seed writes whatever the shipped file holds, so a key present in one
   * and missing from the other would either be written without a schema or
   * declared and never seeded. Both are silent, so they are asserted here.
   */
  public function testShippedKeysMatchTheConfigSchema(): void {
    $schema = Yaml::parseFile($this->moduleRoot() . '/config/schema/ys_beacon.schema.yml');
    $declared = array_keys($schema['ys_beacon.settings']['mapping'] ?? []);

    $shipped = array_keys($this->shippedDefaults());
    sort($declared);
    sort($shipped);

    $this->assertSame($declared, $shipped, 'Every shipped key is declared, and every declared key is shipped.');
  }

}
