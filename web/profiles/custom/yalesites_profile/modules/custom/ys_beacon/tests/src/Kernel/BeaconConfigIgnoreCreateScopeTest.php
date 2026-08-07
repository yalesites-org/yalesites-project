<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\config_ignore\ConfigIgnoreConfig;
use Drupal\Core\Config\MemoryStorage;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the per-site Beacon Search API keys survive a fresh config import.
 *
 * BeaconIndexManager writes a site's own Azure index name, endpoint and
 * read-only flag onto the shared Search API config, and those keys are
 * config-ignored so they survive later deploys
 * (yalesites-org/YaleSites-Internal#1387).
 *
 * config_ignore's create handling unsets an ignored key outright rather than
 * blanking it, so on a site that has no Beacon server yet - a create, not an
 * update - an ignore on database_name strips the key entirely. ai_search's
 * NewServerEventSubscriber then reads it as NULL and aborts the whole
 * config:import with a TypeError, which is what broke the platform-wide deploy
 * (yalesites-org/YaleSites-Internal#1393).
 *
 * The ignores are therefore registered by
 * ys_beacon_config_ignore_ignored_alter() instead of being listed in
 * config_ignore.settings: config_ignore transforms
 * the import storage before any module is installed, so a synced entry also
 * applies to sites that have never had Beacon, where the module's hook cannot
 * run to scope it.
 *
 * @group ys_beacon
 */
class BeaconConfigIgnoreCreateScopeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'config_ignore',
  ];

  /**
   * The config-ignore entries holding per-site Beacon values.
   */
  private const PER_SITE_ENTRIES = [
    'search_api.server.ys_beacon:backend_config.database_settings.database_name',
    'search_api.server.ys_beacon:backend_config.database_settings.url',
    'search_api.index.ys_beacon:read_only',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['config_ignore']);
    // The hook lives in a procedural .module file; ys_beacon itself is not
    // installed here (its dependency tree is large and irrelevant to the
    // config_ignore scoping under test), so load the file directly.
    require_once dirname(__DIR__, 3) . '/ys_beacon.module';
  }

  /**
   * A cold site's import must keep the shipped Beacon keys.
   *
   * This runs the real import storage transformation - the same service
   * ConfigImporter uses to build its changelist - against the shipped
   * config_ignore.settings, with ys_beacon NOT installed. That is exactly the
   * state a customer site is in when a release first brings Beacon to it, and
   * it is the state in which the module's own alter hook cannot run.
   */
  public function testColdImportKeepsShippedBeaconKeys(): void {
    $this->config('config_ignore.settings')
      ->setData($this->shippedConfig('config_ignore.settings'))
      ->save();

    $sync = new MemoryStorage();
    foreach (['search_api.server.ys_beacon', 'search_api.index.ys_beacon'] as $name) {
      $sync->write($name, $this->shippedConfig($name));
    }

    $transformed = \Drupal::service('config.import_transformer')->transform($sync);

    $server = $transformed->read('search_api.server.ys_beacon');
    $this->assertArrayHasKey(
      'database_name',
      $server['backend_config']['database_settings'],
      'A cold import keeps database_name, so ai_search\'s NewServerEventSubscriber never receives NULL and the import is not aborted.',
    );
    $this->assertSame('', $server['backend_config']['database_settings']['database_name']);

    $index = $transformed->read('search_api.index.ys_beacon');
    $this->assertArrayHasKey('read_only', $index, 'A cold import keeps the shipped read_only flag.');
  }

  /**
   * The per-site keys stay ignored everywhere except import-create.
   *
   * Import-update is what makes a value stick across deploys once a site has
   * been provisioned, or once someone edits it by hand; export keeps it out of
   * synced config. Only import-create is skipped, so a site without the server
   * config gets the shipped defaults rather than a stripped key.
   */
  public function testHookIgnoresEverythingButImportCreate(): void {
    // Simple mode broadcasts every entry to every direction and operation; this
    // mirrors how config_ignore builds the object before invoking the alter.
    $ignored = new ConfigIgnoreConfig('simple', ['ys_beacon*']);

    ys_beacon_config_ignore_ignored_alter($ignored);

    foreach (['import', 'export'] as $direction) {
      foreach (['create', 'update', 'delete'] as $operation) {
        $list = $ignored->getList($direction, $operation);
        $message = sprintf('%s/%s', $direction, $operation);
        foreach (self::PER_SITE_ENTRIES as $entry) {
          if ($direction === 'import' && $operation === 'create') {
            $this->assertNotContains($entry, $list, $message);
          }
          else {
            $this->assertContains($entry, $list, $message);
          }
        }
        // Unrelated Beacon config keeps its protection on every operation.
        $this->assertContains('ys_beacon*', $list, $message);
      }
    }
  }

  /**
   * The per-site keys are not listed in synced config_ignore settings.
   *
   * Listing them there is what caused the deploy failure: the entry applies
   * before ys_beacon is installed, and the module's hook cannot scope it away
   * from create at that point.
   */
  public function testPerSiteKeysAreNotInSyncedConfigIgnore(): void {
    $shipped = $this->shippedConfig('config_ignore.settings')['ignored_config_entities'];

    foreach (self::PER_SITE_ENTRIES as $entry) {
      $this->assertNotContains($entry, $shipped);
    }
  }

  /**
   * Reads a configuration object as shipped in the profile's synced config.
   *
   * @param string $name
   *   The config object name.
   *
   * @return array
   *   The decoded configuration data.
   */
  private function shippedConfig(string $name): array {
    $path = dirname(__DIR__, 6) . '/config/sync/' . $name . '.yml';
    $this->assertFileExists($path);
    return Yaml::decode(file_get_contents($path));
  }

}
