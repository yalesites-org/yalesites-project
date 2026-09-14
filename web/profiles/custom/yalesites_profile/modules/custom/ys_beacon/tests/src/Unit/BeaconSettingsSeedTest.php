<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\RagRetriever;
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
    $config->method('getRawData')->willReturn([]);
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
   * A complete existing configuration is left completely untouched.
   */
  public function testDoesNotClobberExistingSettings(): void {
    $config = $this->createMock(Config::class);
    // Every shipped key already present, so there is no gap to fill.
    $config->method('getRawData')->willReturn($this->shippedDefaults());
    $config->expects($this->never())->method('setData');
    $config->expects($this->never())->method('save');
    $this->setContainer($config);

    $this->assertFalse(_ys_beacon_seed_settings(), 'Nothing was written.');
  }

  /**
   * A partially recreated configuration has only its gaps filled.
   *
   * Once the config import has deleted the settings
   * (yalesites-org/YaleSites-Internal#1491), the next runtime write recreates
   * the object holding only that writer's own keys -
   * BeaconPlatformAdminSetting::submitSettings() writes three,
   * BeaconIndexManager::propagateConnection() two. Those keys are the site's
   * real state and must survive; every other shipped default has to come back,
   * or it reads NULL for good.
   */
  public function testFillsGapsWithoutOverwritingExistingKeys(): void {
    $existing = [
      'enable_chat' => TRUE,
      'platform_authorized' => TRUE,
      'azure_index_name' => 'site-specific-index',
    ];

    $captured = NULL;
    $config = $this->createMock(Config::class);
    $config->method('getRawData')->willReturn($existing);
    $config->expects($this->once())
      ->method('setData')
      ->with($this->callback(function (array $data) use (&$captured): bool {
        $captured = $data;
        return TRUE;
      }))
      ->willReturnSelf();
    $config->expects($this->once())->method('save')->willReturnSelf();
    $this->setContainer($config);

    $this->assertTrue(_ys_beacon_seed_settings(), 'A gap was filled.');

    // The site's own values win over the shipped defaults.
    $this->assertTrue($captured['enable_chat'], 'The live chat toggle survives.');
    $this->assertTrue($captured['platform_authorized'], 'Authorization survives.');
    $this->assertSame('site-specific-index', $captured['azure_index_name']);

    // Everything the partial write never set is restored.
    $this->assertTrue($captured['streaming'], 'Streaming comes back on.');
    $this->assertSame(10, $captured['top_k'], 'top_k comes back.');
    $shipped_keys = array_keys($this->shippedDefaults());
    $captured_keys = array_keys($captured);
    sort($shipped_keys);
    sort($captured_keys);
    $this->assertSame($shipped_keys, $captured_keys, 'The full shipped key set is present.');
  }

  /**
   * The runtime fallback constant matches the shipped top_k default.
   *
   * The two are duplicated by necessity - the shipped file cannot reference a
   * PHP constant, and RagRetriever cannot read the file on every query - so
   * this is what makes the "keep in sync" comment on top_k enforceable rather
   * than aspirational. A site missing the key must behave like a fresh
   * install.
   */
  public function testRuntimeFallbackMatchesShippedTopK(): void {
    $this->assertSame(
      $this->shippedDefaults()['top_k'],
      RagRetriever::DEFAULT_TOP_K,
      'RagRetriever::DEFAULT_TOP_K drifted from the shipped top_k default.'
    );
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
