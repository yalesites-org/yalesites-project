<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconIndexManager;

/**
 * Tests Azure AI Search index name sanitization.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconIndexManager
 */
class BeaconIndexManagerTest extends UnitTestCase {

  /**
   * Clears Pantheon environment variables so site-id tests are deterministic.
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('PANTHEON_SITE_NAME');
    putenv('PANTHEON_ENVIRONMENT');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('PANTHEON_SITE_NAME');
    putenv('PANTHEON_ENVIRONMENT');
    parent::tearDown();
  }

  /**
   * @covers ::sanitizeIndexName
   * @dataProvider providerSanitizeIndexName
   */
  public function testSanitizeIndexName(string $input, string $expected): void {
    $this->assertSame($expected, BeaconIndexManager::sanitizeIndexName($input));
  }

  /**
   * Data provider for index name sanitization.
   */
  public static function providerSanitizeIndexName(): array {
    return [
      'pantheon site and environment' => ['my-site-live', 'my-site-live'],
      'uppercase lowered' => ['My-Site-DEV', 'my-site-dev'],
      'underscores and spaces become dashes' => ['my_site name', 'my-site-name'],
      'consecutive separators collapse' => ['my--site__test', 'my-site-test'],
      'leading and trailing dashes trimmed' => ['-my-site-', 'my-site'],
      'invalid characters replaced' => ['site.yale.edu/path', 'site-yale-edu-path'],
      'long names truncated to 128 chars' => [
        str_repeat('a', 130),
        str_repeat('a', 128),
      ],
      'truncation never ends with a dash' => [
        str_repeat('a', 127) . '-tail',
        str_repeat('a', 127),
      ],
    ];
  }

  /**
   * A read-only borrow persists the connection into real Search API config.
   *
   * The call writes the Azure index name onto the search server's database name
   * and the read-only flag onto the index entity, both override-free, plus the
   * ys_beacon.settings preference - so the real config (not just a runtime
   * override) is authoritative and the chat query targets the collection.
   *
   * @covers ::propagateConnection
   */
  public function testPropagateConnectionWritesRealConfig(): void {
    $captured = [];
    // Currently a writable "old-name" site; borrow "borrowed-collection".
    $manager = $this->buildManagerCapturing($captured, 'old-name', FALSE);

    $manager->propagateConnection('borrowed-collection', TRUE);

    // The Beacon settings preference is saved.
    $this->assertSame('borrowed-collection', $captured['settings']['azure_index_name']);
    $this->assertTrue($captured['settings']['read_only']);
    // The server's Azure database name is updated; sibling backend_config keys
    // are preserved (merged into the existing config, not replaced).
    $this->assertSame('borrowed-collection', $captured['server']['backend_config']['database_settings']['database_name']);
    $this->assertSame('portkey', $captured['server']['backend_config']['embeddings_engine']);
    // The index read-only flag is persisted on the entity.
    $this->assertTrue($captured['index']['read_only']);
  }

  /**
   * A writable (non-borrow) connection sets the name and clears read-only.
   *
   * @covers ::propagateConnection
   */
  public function testPropagateConnectionWritableClearsReadOnly(): void {
    $captured = [];
    // Currently a read-only "old" borrow; switch to a writable "my-own-index".
    $manager = $this->buildManagerCapturing($captured, 'old', TRUE);

    $manager->propagateConnection('my-own-index', FALSE);

    $this->assertSame('my-own-index', $captured['settings']['azure_index_name']);
    $this->assertFalse($captured['settings']['read_only']);
    $this->assertSame('my-own-index', $captured['server']['backend_config']['database_settings']['database_name']);
    $this->assertFalse($captured['index']['read_only']);
  }

  /**
   * An unchanged connection writes nothing (no redundant saves or subscribers).
   *
   * @covers ::propagateConnection
   */
  public function testPropagateConnectionSkipsUnchangedValues(): void {
    $captured = [];
    // Already read-only on "shared-idx"; re-save the identical values.
    $manager = $this->buildManagerCapturing($captured, 'shared-idx', TRUE);

    $manager->propagateConnection('shared-idx', TRUE);

    // Nothing changed, so no store is written (a missing key means skipped).
    $this->assertArrayNotHasKey('settings', $captured);
    $this->assertArrayNotHasKey('server', $captured);
    $this->assertArrayNotHasKey('index', $captured);
  }

  /**
   * Builds a BeaconIndexManager whose config writes are captured by reference.
   *
   * @param array $captured
   *   Populated with the values written to ys_beacon.settings ('settings'), the
   *   search server ('server'), and the search index ('index'); a store absent
   *   from the array means propagateConnection() skipped writing it.
   * @param string $current_name
   *   The index name currently persisted (settings + server database name), so
   *   change-detection can decide whether a write is needed.
   * @param bool $current_read_only
   *   The read-only flag currently persisted (settings + index entity).
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexManager
   *   The manager under test with only the two used dependencies wired.
   */
  private function buildManagerCapturing(
    array &$captured,
    string $current_name,
    bool $current_read_only,
  ): BeaconIndexManager {
    $editable = $this->createMock(Config::class);
    $editable->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'azure_index_name' => $current_name,
      'read_only' => $current_read_only,
      default => NULL,
    });
    $editable->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $editable) {
      $captured['settings'][$key] = $value;
      return $editable;
    });
    $editable->method('save')->willReturnSelf();

    $immutable = $this->createMock(Config::class);
    $immutable->method('get')->willReturnCallback(
      fn (string $key) => in_array($key, ['search_server_id', 'search_index_id'], TRUE) ? 'ys_beacon' : NULL,
    );

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')->with('ys_beacon.settings')->willReturn($editable);
    $config_factory->method('get')->with('ys_beacon.settings')->willReturn($immutable);

    $server = $this->createMock(ServerInterface::class);
    $server->method('get')->with('backend_config')->willReturn([
      'database_settings' => ['database_name' => $current_name],
      'embeddings_engine' => 'portkey',
    ]);
    $server->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $server) {
      $captured['server'][$key] = $value;
      return $server;
    });
    $server->method('save')->willReturn(1);
    $server_storage = $this->createMock(ConfigEntityStorageInterface::class);
    $server_storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($server);

    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn($current_read_only);
    $index->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $index) {
      $captured['index'][$key] = $value;
      return $index;
    });
    $index->method('save')->willReturn(1);
    $index_storage = $this->createMock(ConfigEntityStorageInterface::class);
    $index_storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($index);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(
      fn (string $type) => $type === 'search_api_server' ? $server_storage : $index_storage,
    );

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $config_property = new \ReflectionProperty($manager, 'configFactory');
    $config_property->setAccessible(TRUE);
    $config_property->setValue($manager, $config_factory);
    $etm_property = new \ReflectionProperty($manager, 'entityTypeManager');
    $etm_property->setAccessible(TRUE);
    $etm_property->setValue($manager, $etm);

    return $manager;
  }

  /**
   * The site key is the Pantheon site and environment, sanitized.
   *
   * @covers ::getSiteId
   */
  public function testGetSiteIdUsesPantheonSiteAndEnvironment(): void {
    putenv('PANTHEON_SITE_NAME=My-Site');
    putenv('PANTHEON_ENVIRONMENT=LIVE');
    $manager = $this->buildManagerWithSiteUuid('unused-uuid');

    $this->assertSame('my-site-live', $manager->getSiteId());
  }

  /**
   * Off Pantheon (local dev), the site key falls back to the site UUID slug.
   *
   * @covers ::getSiteId
   */
  public function testGetSiteIdFallsBackToSiteUuidLocally(): void {
    $manager = $this->buildManagerWithSiteUuid('cb91b104-1d1e-42b7-a3a5-d0f6715e459c');

    $this->assertSame('beacon-cb91b104', $manager->getSiteId());
  }

  /**
   * The index schema carries a retrievable, filterable site_id column.
   *
   * A shared collection can then store and scope by the per-site key.
   *
   * @covers ::buildIndexSchema
   */
  public function testBuildIndexSchemaIncludesRetrievableSiteId(): void {
    $manager = $this->buildManagerWithSiteUuid('unused-uuid');
    $method = new \ReflectionMethod($manager, 'buildIndexSchema');
    $method->setAccessible(TRUE);

    $schema = $method->invoke($manager, 'shared-index');
    $fields = array_column($schema['fields'], NULL, 'name');

    $this->assertArrayHasKey('site_id', $fields);
    $this->assertTrue($fields['site_id']['retrievable']);
    $this->assertTrue($fields['site_id']['filterable']);
  }

  /**
   * Re-provisioning drops an existing index before recreating it.
   *
   * @covers ::reprovision
   */
  public function testReprovisionDropsExistingIndexBeforeRecreating(): void {
    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['indexExists', 'deleteIndex', 'provision'])
      ->getMock();
    $manager->method('indexExists')->with('shared-index')->willReturn(TRUE);
    $manager->expects($this->once())->method('deleteIndex')->with('shared-index');
    $manager->expects($this->once())->method('provision')->with('shared-index')->willReturn('shared-index');

    $this->assertSame('shared-index', $manager->reprovision('shared-index'));
  }

  /**
   * A missing index is provisioned without a destructive delete.
   *
   * @covers ::reprovision
   */
  public function testReprovisionSkipsDeleteWhenIndexMissing(): void {
    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['indexExists', 'deleteIndex', 'provision'])
      ->getMock();
    $manager->method('indexExists')->with('shared-index')->willReturn(FALSE);
    $manager->expects($this->never())->method('deleteIndex');
    $manager->expects($this->once())->method('provision')->with('shared-index')->willReturn('shared-index');

    $this->assertSame('shared-index', $manager->reprovision('shared-index'));
  }

  /**
   * Builds a manager with a stubbed config factory for site-id/schema tests.
   *
   * The config factory yields a fixed system.site UUID and the default Beacon
   * server embedding dimensions.
   *
   * @param string $uuid
   *   The system.site UUID to return.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexManager
   *   The manager under test with only its config factory wired.
   */
  private function buildManagerWithSiteUuid(string $uuid): BeaconIndexManager {
    $site = $this->createMock(Config::class);
    $site->method('get')->willReturnCallback(fn (string $key) => $key === 'uuid' ? $uuid : NULL);

    $settings = $this->createMock(Config::class);
    $settings->method('get')->willReturnCallback(
      fn (string $key) => in_array($key, ['search_server_id', 'search_index_id'], TRUE) ? 'ys_beacon' : NULL,
    );

    $server = $this->createMock(Config::class);
    $server->method('get')->willReturnCallback(
      fn (string $key) => $key === 'backend_config.embeddings_engine_configuration.dimensions' ? 1536 : NULL,
    );

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(fn (string $name) => match ($name) {
      'system.site' => $site,
      'ys_beacon.settings' => $settings,
      'search_api.server.ys_beacon' => $server,
      default => $this->createMock(Config::class),
    });

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $config_property = new \ReflectionProperty($manager, 'configFactory');
    $config_property->setAccessible(TRUE);
    $config_property->setValue($manager, $config_factory);

    return $manager;
  }

}
