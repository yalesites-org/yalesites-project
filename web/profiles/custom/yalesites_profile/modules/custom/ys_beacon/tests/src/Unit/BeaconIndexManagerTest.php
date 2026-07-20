<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

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
   * The Azure schema declares queryable citation title and URL fields.
   *
   * Cross-site citations read these back off each document, so both must be
   * retrievable; they are also searchable, filterable, and sortable so a page's
   * chunks can be found, filtered, and grouped in the Azure portal for
   * debugging (yalesites-org/YaleSites-Internal#1439).
   *
   * @covers ::buildIndexSchema
   */
  public function testBuildIndexSchemaIncludesCitationFields(): void {
    // All config lookups fall through to defaults (server id, dimensions).
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn(NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $config_property = new \ReflectionProperty($manager, 'configFactory');
    $config_property->setAccessible(TRUE);
    $config_property->setValue($manager, $config_factory);

    $method = new \ReflectionMethod($manager, 'buildIndexSchema');
    $method->setAccessible(TRUE);
    $schema = $method->invoke($manager, 'test-index');

    $fields = array_column($schema['fields'], NULL, 'name');
    $this->assertArrayHasKey('citation_title', $fields);
    $this->assertArrayHasKey('citation_url', $fields);
    // Retrievable for citations and fully queryable (searchable, filterable,
    // sortable) for portal debugging.
    foreach (['citation_title', 'citation_url'] as $name) {
      $this->assertTrue($fields[$name]['retrievable']);
      $this->assertTrue($fields[$name]['searchable']);
      $this->assertTrue($fields[$name]['filterable']);
      $this->assertTrue($fields[$name]['sortable']);
      $this->assertTrue($fields[$name]['facetable']);
    }
  }

  /**
   * The Azure schema makes content searchable for portal keyword debugging.
   *
   * Content stays retrievable and becomes searchable so chunk text can be
   * keyword-searched in the Azure portal, but it is not filtered, sorted, or
   * faceted on — matching on full chunk text is not useful
   * (yalesites-org/YaleSites-Internal#1439).
   *
   * @covers ::buildIndexSchema
   */
  public function testBuildIndexSchemaContentIsSearchable(): void {
    $manager = $this->buildManagerWithSiteUuid('unused-uuid');
    $method = new \ReflectionMethod($manager, 'buildIndexSchema');
    $method->setAccessible(TRUE);

    $schema = $method->invoke($manager, 'shared-index');
    $fields = array_column($schema['fields'], NULL, 'name');

    $this->assertArrayHasKey('content', $fields);
    $this->assertTrue($fields['content']['retrievable']);
    $this->assertTrue($fields['content']['searchable']);
    $this->assertFalse($fields['content']['filterable']);
    $this->assertFalse($fields['content']['sortable']);
    $this->assertFalse($fields['content']['facetable']);
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
   * The index schema carries a filterable, sortable updated_at date column.
   *
   * The last-indexed timestamp is stored as a typed Edm.DateTimeOffset (not a
   * string attribute) so chunks can be sorted and filtered by index freshness
   * directly in the Azure portal (yalesites-org/YaleSites-Internal#1434). It is
   * freshness metadata, so it is retrievable but not full-text searched or
   * faceted on.
   *
   * @covers ::buildIndexSchema
   */
  public function testBuildIndexSchemaIncludesUpdatedAt(): void {
    $manager = $this->buildManagerWithSiteUuid('unused-uuid');
    $method = new \ReflectionMethod($manager, 'buildIndexSchema');
    $method->setAccessible(TRUE);

    $schema = $method->invoke($manager, 'shared-index');
    $fields = array_column($schema['fields'], NULL, 'name');

    $this->assertArrayHasKey('updated_at', $fields);
    $this->assertSame('Edm.DateTimeOffset', $fields['updated_at']['type']);
    $this->assertTrue($fields['updated_at']['retrievable']);
    $this->assertTrue($fields['updated_at']['filterable']);
    $this->assertTrue($fields['updated_at']['sortable']);
    $this->assertFalse($fields['updated_at']['searchable']);
    $this->assertFalse($fields['updated_at']['facetable']);
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
    $manager->expects($this->once())->method('provision')->with('shared-index', FALSE)->willReturn('shared-index');

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
    $manager->expects($this->once())->method('provision')->with('shared-index', FALSE)->willReturn('shared-index');

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

  /**
   * EnsureIndex adopts a pre-existing index and logs that it was already there.
   *
   * An index already on the target service is adopted as-is (never re-created)
   * and the adoption is logged at notice level for traceability - neutrally,
   * since it is expected on a borrow-to-writable switch and only surprising on
   * a first-time provision (yalesites-org/YaleSites-Internal#1440).
   *
   * @covers ::ensureIndex
   */
  public function testEnsureIndexAdoptsExistingIndexAndLogsIt(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice')
      ->with($this->stringContains('already'), $this->anything());
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->never())->method('error');

    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['indexExists', 'countIndexes', 'createIndex'])
      ->getMock();
    $manager->method('indexExists')->with('shared-index')->willReturn(TRUE);
    // An adopted index is neither counted against capacity nor re-created.
    $manager->expects($this->never())->method('countIndexes');
    $manager->expects($this->never())->method('createIndex');
    $this->injectLogger($manager, $logger);

    $this->assertSame('shared-index', $manager->ensureIndex('shared-index'));
  }

  /**
   * EnsureIndex creates a missing index when the service has capacity.
   *
   * @covers ::ensureIndex
   */
  public function testEnsureIndexCreatesWhenServiceHasCapacity(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice')
      ->with($this->stringContains('Created'), $this->anything());
    $logger->expects($this->never())->method('error');

    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['indexExists', 'countIndexes', 'maxIndexes', 'createIndex'])
      ->getMock();
    $manager->method('indexExists')->with('new-index')->willReturn(FALSE);
    $manager->method('countIndexes')->willReturn(10);
    $manager->method('maxIndexes')->willReturn(50);
    $manager->expects($this->once())->method('createIndex')->with('new-index');
    $this->injectLogger($manager, $logger);

    $this->assertSame('new-index', $manager->ensureIndex('new-index'));
  }

  /**
   * EnsureIndex refuses to create a new index when the service is at capacity.
   *
   * A count meeting or exceeding the configured limit is a hard failure:
   * nothing is created, an error is logged pointing at the fix (provision a new
   * service and update the Pantheon secret), and a RuntimeException propagates
   * so callers persist nothing (yalesites-org/YaleSites-Internal#1440).
   *
   * @covers ::ensureIndex
   * @covers ::assertCapacity
   */
  public function testEnsureIndexFailsWhenServiceAtCapacity(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')
      ->with($this->stringContains('capacity'), $this->anything());

    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['indexExists', 'countIndexes', 'maxIndexes', 'createIndex'])
      ->getMock();
    $manager->method('indexExists')->with('new-index')->willReturn(FALSE);
    $manager->method('countIndexes')->willReturn(50);
    $manager->method('maxIndexes')->willReturn(50);
    $manager->expects($this->never())->method('createIndex');
    $this->injectLogger($manager, $logger);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('capacity');
    $manager->ensureIndex('new-index');
  }

  /**
   * CountIndexes returns the number of indexes reported by the service.
   *
   * Azure AI Search returns the index list under a top-level "value" key; the
   * length of that array is the total index count the capacity guard checks.
   *
   * @covers ::countIndexes
   * @dataProvider providerCountIndexes
   */
  public function testCountIndexes(string $json, int $expected): void {
    $manager = $this->buildManagerWithHttpBody($json);
    $this->assertSame($expected, $manager->countIndexes());
  }

  /**
   * Data provider for the service index count.
   */
  public static function providerCountIndexes(): array {
    return [
      'three indexes' => ['{"value":[{"name":"a"},{"name":"b"},{"name":"c"}]}', 3],
      'empty service' => ['{"value":[]}', 0],
    ];
  }

  /**
   * CountIndexes fails closed on a 2xx that is not a real index listing.
   *
   * A repointed endpoint answering 200 with a non-Azure body must not be read
   * as an empty service, which would bypass the capacity guard
   * (yalesites-org/YaleSites-Internal#1440).
   *
   * @covers ::countIndexes
   * @dataProvider providerMalformedIndexListing
   */
  public function testCountIndexesRejectsMalformedResponse(string $json): void {
    $manager = $this->buildManagerWithHttpBody($json);
    $this->expectException(\RuntimeException::class);
    $manager->countIndexes();
  }

  /**
   * Data provider for 2xx bodies that are not a valid index listing.
   */
  public static function providerMalformedIndexListing(): array {
    return [
      'no value key' => ['{"error":"nope"}'],
      'non-json html body' => ['<html>login</html>'],
      'value is a scalar' => ['{"value":5}'],
    ];
  }

  /**
   * MaxIndexes reads the configured limit, falling back to the shipped default.
   *
   * @covers ::maxIndexes
   */
  public function testMaxIndexesReadsConfigWithDefaultFallback(): void {
    $configured = $this->invokeMaxIndexes($this->buildManagerWithBeaconSettings(['max_indexes' => 40]));
    $this->assertSame(40, $configured);

    $default = $this->invokeMaxIndexes($this->buildManagerWithBeaconSettings([]));
    $this->assertSame(BeaconIndexManager::DEFAULT_MAX_INDEXES, $default);
  }

  /**
   * Pins the resolved endpoint so a later secret change skips this site.
   *
   * @covers ::pinSearchUrl
   */
  public function testPinSearchUrlPersistsResolvedEndpoint(): void {
    $captured = [];
    $manager = $this->buildManagerForPin('https://svc.search.windows.net', '', $captured);
    $this->assertTrue($manager->pinSearchUrl());
    $this->assertSame('https://svc.search.windows.net', $captured['url'] ?? NULL);
  }

  /**
   * PinSearchUrl writes nothing when the endpoint is already pinned unchanged.
   *
   * @covers ::pinSearchUrl
   */
  public function testPinSearchUrlSkipsWhenUnchanged(): void {
    $captured = [];
    $manager = $this->buildManagerForPin(
      'https://svc.search.windows.net',
      'https://svc.search.windows.net',
      $captured,
    );
    // The endpoint still resolved, so it is reported pinned even though the
    // already-current value is not re-written.
    $this->assertTrue($manager->pinSearchUrl());
    $this->assertArrayNotHasKey('url', $captured);
  }

  /**
   * PinSearchUrl persists nothing when no endpoint resolves (keep the secret).
   *
   * @covers ::pinSearchUrl
   */
  public function testPinSearchUrlSkipsWhenNoEndpointResolves(): void {
    $captured = [];
    $manager = $this->buildManagerForPin('', '', $captured);
    // Nothing resolved: reported unpinned so callers can surface it, and no
    // value is written.
    $this->assertFalse($manager->pinSearchUrl());
    $this->assertArrayNotHasKey('url', $captured);
  }

  /**
   * Injects a logger into a manager under test.
   */
  private function injectLogger(BeaconIndexManager $manager, LoggerInterface $logger): void {
    $this->setProperty($manager, 'logger', $logger);
  }

  /**
   * Invokes the protected maxIndexes() method.
   */
  private function invokeMaxIndexes(BeaconIndexManager $manager): int {
    $method = new \ReflectionMethod($manager, 'maxIndexes');
    $method->setAccessible(TRUE);
    return $method->invoke($manager);
  }

  /**
   * Builds a manager whose Azure management API returns the given JSON body.
   *
   * Wires the real request() path (config factory, key repository, HTTP client)
   * so a single stubbed HTTP response drives countIndexes().
   *
   * @param string $json
   *   The JSON body the stubbed HTTP client returns.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexManager
   *   The manager under test with its request() dependencies wired.
   */
  private function buildManagerWithHttpBody(string $json): BeaconIndexManager {
    $vdb = $this->createMock(Config::class);
    $vdb->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'url' => 'https://svc.search.windows.net',
      'api_version' => '2023-11-01',
      'api_key' => 'azure_ai_search_api_key',
      default => NULL,
    });
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      fn (string $name) => $name === 'ai_vdb_provider_azure_ai_search.settings' ? $vdb : $this->createMock(Config::class),
    );

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('admin-key');
    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    $key_repository->method('getKey')->with('azure_ai_search_api_key')->willReturn($key);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn(Utils::streamFor($json));
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willReturn($response);

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $this->setProperty($manager, 'configFactory', $config_factory);
    $this->setProperty($manager, 'keyRepository', $key_repository);
    $this->setProperty($manager, 'httpClient', $http_client);
    return $manager;
  }

  /**
   * Builds a manager whose ys_beacon.settings returns the given values.
   *
   * @param array $settings
   *   The ys_beacon.settings values keyed by config key.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexManager
   *   The manager under test with only its config factory wired.
   */
  private function buildManagerWithBeaconSettings(array $settings): BeaconIndexManager {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ys_beacon.settings')->willReturn($config);

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $this->setProperty($manager, 'configFactory', $config_factory);
    return $manager;
  }

  /**
   * Builds a manager for pinSearchUrl() tests.
   *
   * @param string $resolved_url
   *   The endpoint the VDB config currently resolves to (the override-applied
   *   value pinSearchUrl reads back).
   * @param string $current_pin
   *   The endpoint already pinned on the raw VDB connection config.
   * @param array $captured
   *   Populated with any value written to the VDB connection config.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexManager
   *   The manager under test with only its config factory wired.
   */
  private function buildManagerForPin(string $resolved_url, string $current_pin, array &$captured): BeaconIndexManager {
    // The immutable (override-applied) VDB config pinSearchUrl reads back.
    $immutable = $this->createMock(Config::class);
    $immutable->method('get')->with('url')->willReturn($resolved_url);

    // The editable (raw) VDB config pinSearchUrl writes the pinned url onto.
    $editable = $this->createMock(Config::class);
    $editable->method('get')->with('url')->willReturn($current_pin);
    $editable->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $editable) {
      $captured[$key] = $value;
      return $editable;
    });
    $editable->method('save')->willReturnSelf();

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ai_vdb_provider_azure_ai_search.settings')->willReturn($immutable);
    $config_factory->method('getEditable')->with('ai_vdb_provider_azure_ai_search.settings')->willReturn($editable);

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $this->setProperty($manager, 'configFactory', $config_factory);
    return $manager;
  }

  /**
   * Sets a protected property on the manager under test.
   */
  private function setProperty(BeaconIndexManager $manager, string $name, mixed $value): void {
    $property = new \ReflectionProperty(BeaconIndexManager::class, $name);
    $property->setAccessible(TRUE);
    $property->setValue($manager, $value);
  }

}
