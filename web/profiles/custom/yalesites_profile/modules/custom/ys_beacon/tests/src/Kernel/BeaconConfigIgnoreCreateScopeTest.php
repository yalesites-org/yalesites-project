<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\config_ignore\ConfigIgnoreConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the Beacon server database name is not config-ignored on create.
 *
 * The per-site Azure index (database) name is persisted onto
 * search_api.server.ys_beacon by BeaconIndexManager::propagateConnection() and
 * config-ignored so it survives config import on provisioned sites
 * (yalesites-org/YaleSites-Internal#1387). config_ignore runs in simple mode,
 * which applies every entry to create, update AND delete. On a brand-new site
 * the server is created rather than updated, so the ignore strips database_name
 * from the incoming config; ai_search's NewServerEventSubscriber then calls
 * AzureAiSearchProvider::getCollections(NULL) and aborts the whole config
 * import with a TypeError (yalesites-org/YaleSites-Internal#1393).
 *
 * ys_beacon_config_ignore_ignored_alter() scopes that one ignore away from the
 * create operation on import, so a fresh import keeps the shipped empty default
 * (present, harmless) while the import-update ignore still protects a
 * provisioned site's persisted name on every later deploy and the export
 * ignores are left intact. This test locks that scoping.
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
   * The config-ignore entry protecting the per-site Azure database name.
   */
  private const DATABASE_NAME_ENTRY = 'search_api.server.ys_beacon:backend_config.database_settings.database_name';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The hook lives in a procedural .module file; ys_beacon itself is not
    // installed here (its dependency tree is large and irrelevant to the
    // config_ignore scoping under test), so load the file directly.
    require_once dirname(__DIR__, 3) . '/ys_beacon.module';
  }

  /**
   * The database-name ignore applies on update/delete but not import-create.
   */
  public function testDatabaseNameIgnoreIsScopedAwayFromImportCreate(): void {
    // Simple mode broadcasts every entry to create, update and delete; this
    // mirrors how config_ignore builds the object before invoking the alter.
    $ignored = new ConfigIgnoreConfig('simple', [
      self::DATABASE_NAME_ENTRY,
      'ys_beacon*',
    ]);

    ys_beacon_config_ignore_ignored_alter($ignored);

    $import_create = $ignored->getList('import', 'create');
    $import_update = $ignored->getList('import', 'update');
    $export_create = $ignored->getList('export', 'create');

    // Dropped from create on import (the deploy path): a fresh import keeps the
    // shipped empty default, so the new-server subscriber never gets a null.
    $this->assertNotContains(self::DATABASE_NAME_ENTRY, $import_create);

    // Still ignored on import-update: a provisioned site's persisted per-site
    // name survives every later deploy exactly as before.
    $this->assertContains(self::DATABASE_NAME_ENTRY, $import_update);

    // Export is left untouched, so the per-site name never leaks into synced
    // config on export.
    $this->assertContains(self::DATABASE_NAME_ENTRY, $export_create);

    // Unrelated Beacon config keeps its create-time protection untouched.
    $this->assertContains('ys_beacon*', $import_create);
  }

}
