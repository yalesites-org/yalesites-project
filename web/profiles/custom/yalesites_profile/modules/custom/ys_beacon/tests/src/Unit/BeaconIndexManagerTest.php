<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\BeaconCredentials;
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

    $immutable = $this->serverIdSettingsMock();

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

    $settings = $this->serverIdSettingsMock();

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
      ->onlyMethods(['indexExists', 'countIndexes', 'createIndex'])
      ->getMock();
    $manager->method('indexExists')->with('new-index')->willReturn(FALSE);
    // Well under the fixed MAX_INDEXES (50), so creation proceeds.
    $manager->method('countIndexes')->willReturn(10);
    $manager->expects($this->once())->method('createIndex')->with('new-index');
    $this->injectLogger($manager, $logger);

    $this->assertSame('new-index', $manager->ensureIndex('new-index'));
  }

  /**
   * EnsureIndex refuses to create a new index when the service is at capacity.
   *
   * A count meeting or exceeding the fixed service limit is a hard failure:
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
      ->onlyMethods(['indexExists', 'countIndexes', 'createIndex'])
      ->getMock();
    $manager->method('indexExists')->with('new-index')->willReturn(FALSE);
    // At the fixed MAX_INDEXES (50), so creation is refused.
    $manager->method('countIndexes')->willReturn(50);
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
   * Pins the resolved endpoint onto the server so a secret change skips it.
   *
   * When the server has no endpoint configured, the resolved endpoint (here the
   * secret-backed default) is written onto the server's connection URL - the
   * field an admin edits and the override resolves from - so the site keeps
   * that service even if the shared secret is later repointed
   * (yalesites-org/YaleSites-Internal#1440, #1448).
   *
   * @covers ::pinSearchUrl
   */
  public function testPinSearchUrlPersistsResolvedEndpoint(): void {
    $captured = [];
    $manager = $this->buildManagerForPin('https://svc.search.windows.net', '', $captured);
    $this->assertTrue($manager->pinSearchUrl());
    $this->assertSame('https://svc.search.windows.net', $captured[0] ?? NULL);
  }

  /**
   * PinSearchUrl leaves an endpoint an admin already configured untouched.
   *
   * @covers ::pinSearchUrl
   */
  public function testPinSearchUrlSkipsWhenServerAlreadyConfigured(): void {
    $captured = [];
    $manager = $this->buildManagerForPin(
      'https://svc.search.windows.net',
      'https://svc.search.windows.net',
      $captured,
    );
    // The endpoint resolved, so it is reported pinned, but the server already
    // carries one so nothing is written over it.
    $this->assertTrue($manager->pinSearchUrl());
    $this->assertSame([], $captured);
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
    $this->assertSame([], $captured);
  }

  /**
   * Request() authenticates with the key paired with the configured endpoint.
   *
   * The provisioning path resolves the API key for the site's effective
   * endpoint through the credentials resolver, so it sends the same per-service
   * key the query path uses (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::request
   * @covers ::countIndexes
   */
  public function testRequestUsesEndpointMatchedApiKey(): void {
    $vdb = $this->createMock(Config::class);
    $vdb->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'url' => 'https://svc.search.windows.net',
      'api_version' => '2023-11-01',
      default => NULL,
    });
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      fn (string $name) => $name === 'ai_vdb_provider_azure_ai_search.settings' ? $vdb : $this->createMock(Config::class),
    );

    // The resolver is asked for the key of the site's configured endpoint.
    $credentials = $this->createMock(BeaconCredentials::class);
    $credentials->expects($this->atLeastOnce())->method('apiKeyForEndpoint')
      ->with('https://svc.search.windows.net')->willReturn('endpoint-key');

    $captured = [];
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn(Utils::streamFor('{"value":[]}'));
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willReturnCallback(
      function (string $method, string $uri, array $options) use (&$captured, $response) {
        $captured = $options;
        return $response;
      },
    );

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $this->setProperty($manager, 'configFactory', $config_factory);
    $this->setProperty($manager, 'credentials', $credentials);
    $this->setProperty($manager, 'httpClient', $http_client);

    $manager->countIndexes();

    // The resolved per-endpoint key is what actually authenticates the request.
    $this->assertSame('endpoint-key', $captured['headers']['api-key'] ?? NULL);
  }

  /**
   * Repin() refuses an endpoint that has no key in the map.
   *
   * Moving a site to a service it cannot authenticate against would strand it,
   * so repin fails closed before it writes anything or provisions
   * (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::repin
   */
  public function testRepinRefusesEndpointMissingFromMap(): void {
    $credentials = $this->createMock(BeaconCredentials::class);
    $credentials->method('apiKeyForEndpoint')->willReturn(NULL);

    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['provision'])
      ->getMock();
    // It must not provision when the target endpoint is unusable.
    $manager->expects($this->never())->method('provision');
    $this->setProperty($manager, 'credentials', $credentials);

    $this->expectException(\RuntimeException::class);
    $manager->repin('https://unmapped.search.windows.net');
  }

  /**
   * Repin() pins the normalized endpoint onto the server then provisions.
   *
   * @covers ::repin
   */
  public function testRepinPinsEndpointAndProvisions(): void {
    $credentials = $this->createMock(BeaconCredentials::class);
    $credentials->method('apiKeyForEndpoint')->with('https://new.search.windows.net')->willReturn('KEY');

    $captured = [];
    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['provision'])
      ->getMock();
    $manager->expects($this->once())->method('provision')->willReturn('my-index');
    $this->setProperty($manager, 'credentials', $credentials);
    $this->setProperty($manager, 'entityTypeManager', $this->serverCapturingEtm('', $captured));
    $this->setProperty($manager, 'configFactory', $this->serverIdConfigFactory());
    $this->injectLogger($manager, $this->createMock(LoggerInterface::class));

    $this->assertSame('my-index', $manager->repin('new.search.windows.net'));
    // A scheme-less input is normalized to https before it is pinned onto the
    // server's connection URL.
    $this->assertSame('https://new.search.windows.net', $captured[0] ?? NULL);
  }

  /**
   * Repin() restores the previous endpoint when provisioning fails.
   *
   * The VDB url is config-ignored, so a failed repin that left the new endpoint
   * pinned would strand the site pointing at an index-less service with no
   * deploy-time recovery. repin() must roll the endpoint back on failure
   * (yalesites-org/YaleSites-Internal#1448).
   *
   * @covers ::repin
   */
  public function testRepinRestoresPreviousEndpointWhenProvisionFails(): void {
    $credentials = $this->createMock(BeaconCredentials::class);
    $credentials->method('apiKeyForEndpoint')->willReturn('KEY');

    $captured = [];
    $manager = $this->getMockBuilder(BeaconIndexManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['provision'])
      ->getMock();
    // Provisioning on the new service fails (e.g. it is at the index cap).
    $manager->method('provision')->willThrowException(new \RuntimeException('at capacity'));
    $this->setProperty($manager, 'credentials', $credentials);
    $this->setProperty($manager, 'entityTypeManager', $this->serverCapturingEtm('https://old.search.windows.net', $captured));
    $this->setProperty($manager, 'configFactory', $this->serverIdConfigFactory());
    $this->injectLogger($manager, $this->createMock(LoggerInterface::class));

    try {
      $manager->repin('new.search.windows.net');
      $this->fail('Expected the failed provision to propagate.');
    }
    catch (\RuntimeException $e) {
      // Expected: the provision failure propagates to the caller.
    }

    // The new endpoint was pinned, then rolled back to the previous one.
    $this->assertSame(
      ['https://new.search.windows.net', 'https://old.search.windows.net'],
      $captured,
    );
  }

  /**
   * Injects a logger into a manager under test.
   */
  private function injectLogger(BeaconIndexManager $manager, LoggerInterface $logger): void {
    $this->setProperty($manager, 'logger', $logger);
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
      default => NULL,
    });
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      fn (string $name) => $name === 'ai_vdb_provider_azure_ai_search.settings' ? $vdb : $this->createMock(Config::class),
    );

    $credentials = $this->createMock(BeaconCredentials::class);
    $credentials->method('apiKeyForEndpoint')->willReturn('admin-key');

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn(Utils::streamFor($json));
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willReturn($response);

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $this->setProperty($manager, 'configFactory', $config_factory);
    $this->setProperty($manager, 'credentials', $credentials);
    $this->setProperty($manager, 'httpClient', $http_client);
    return $manager;
  }

  /**
   * Builds a manager for pinSearchUrl() tests.
   *
   * @param string $resolved_url
   *   The override-applied VDB endpoint pinSearchUrl reads back (the server's
   *   configured URL, or the secret-backed default when it has none).
   * @param string $current_field
   *   The endpoint URL already stored on the server's connection config.
   * @param array $captured
   *   Populated with each endpoint URL written onto the server, in order.
   *
   * @return \Drupal\ys_beacon\Service\BeaconIndexManager
   *   The manager under test with its config factory and entity manager wired.
   */
  private function buildManagerForPin(string $resolved_url, string $current_field, array &$captured): BeaconIndexManager {
    // The override-applied VDB endpoint pinSearchUrl reads back.
    $vdb = $this->createMock(Config::class);
    $vdb->method('get')->with('url')->willReturn($resolved_url);
    $settings = $this->serverIdSettingsMock();
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(fn (string $name) => match ($name) {
      'ai_vdb_provider_azure_ai_search.settings' => $vdb,
      'ys_beacon.settings' => $settings,
      default => $this->createMock(Config::class),
    });

    $manager = (new \ReflectionClass(BeaconIndexManager::class))->newInstanceWithoutConstructor();
    $this->setProperty($manager, 'configFactory', $config_factory);
    $this->setProperty($manager, 'entityTypeManager', $this->serverCapturingEtm($current_field, $captured));
    return $manager;
  }

  /**
   * Builds an entity type manager whose Beacon server captures endpoint writes.
   *
   * The mocked search server starts with the given connection URL and records
   * every URL subsequently written onto its backend config, so pin/repin tests
   * can assert what was persisted (and rolled back).
   *
   * @param string $current_field
   *   The endpoint URL initially stored on the server's backend config.
   * @param array $captured
   *   Populated with each endpoint URL written onto the server, in order.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The mocked entity type manager.
   */
  private function serverCapturingEtm(string $current_field, array &$captured): EntityTypeManagerInterface {
    $server = $this->createMock(ServerInterface::class);
    $server->method('get')->with('backend_config')->willReturn([
      'database_settings' => ['database_name' => 'idx', 'url' => $current_field],
      'embeddings_engine' => 'portkey',
    ]);
    $server->method('set')->willReturnCallback(function (string $key, $value) use (&$captured, $server) {
      if ($key === 'backend_config') {
        $captured[] = $value['database_settings']['url'] ?? NULL;
      }
      return $server;
    });
    $server->method('save')->willReturn(1);

    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('loadOverrideFree')->with('ys_beacon')->willReturn($server);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('search_api_server')->willReturn($storage);
    return $etm;
  }

  /**
   * Builds a config factory that resolves the default Beacon server id.
   *
   * Repin() and pinSearchUrl() read search_server_id to load the server and
   * reset the VDB config after writing it; this wires both to no-op defaults.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The mocked config factory.
   */
  private function serverIdConfigFactory(): ConfigFactoryInterface {
    $settings = $this->serverIdSettingsMock();
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($settings);
    return $config_factory;
  }

  /**
   * Builds a ys_beacon.settings mock resolving the default server/index ids.
   *
   * @return \Drupal\Core\Config\Config
   *   A config mock whose search_server_id and search_index_id both resolve to
   *   'ys_beacon' and every other key to NULL.
   */
  private function serverIdSettingsMock(): Config {
    $settings = $this->createMock(Config::class);
    $settings->method('get')->willReturnCallback(
      fn (string $key) => in_array($key, ['search_server_id', 'search_index_id'], TRUE) ? 'ys_beacon' : NULL,
    );
    return $settings;
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
