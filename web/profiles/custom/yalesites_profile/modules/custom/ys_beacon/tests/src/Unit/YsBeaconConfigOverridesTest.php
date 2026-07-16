<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\StorageInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Config\YsBeaconConfigOverrides;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests per-site Beacon config overrides.
 *
 * Covers the Azure endpoint URL resolution, the chat-toggle index status
 * safety net, and the configurable search server/index machine names.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Config\YsBeaconConfigOverrides
 */
class YsBeaconConfigOverridesTest extends UnitTestCase {

  /**
   * The VDB connection config name whose URL is overridden.
   */
  protected const VDB_CONFIG = 'ai_vdb_provider_azure_ai_search.settings';

  /**
   * The Search API index config name whose status is overridden.
   */
  protected const INDEX_CONFIG = 'search_api.index.ys_beacon';

  /**
   * The Beacon settings config name whose chat toggle is overridden.
   */
  protected const SETTINGS_CONFIG = 'ys_beacon.settings';

  /**
   * Builds the override with stubbed settings and a stubbed key repository.
   *
   * @param array $settings
   *   The raw ys_beacon.settings returned by the config storage.
   * @param array $keys
   *   Map of key id => resolved value. A missing id resolves to no key entity.
   *
   * @return \Drupal\ys_beacon\Config\YsBeaconConfigOverrides
   *   The override under test, with a key.repository placed in the container.
   */
  protected function buildOverride(array $settings, array $keys): YsBeaconConfigOverrides {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->with('ys_beacon.settings')->willReturn($settings);

    $repository = $this->createMock(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturnCallback(function (string $id) use ($keys): ?KeyInterface {
      if (!array_key_exists($id, $keys)) {
        return NULL;
      }
      $key = $this->createMock(KeyInterface::class);
      $key->method('getKeyValue')->willReturn($keys[$id]);
      return $key;
    });

    $container = new ContainerBuilder();
    $container->set('key.repository', $repository);
    \Drupal::setContainer($container);

    return new YsBeaconConfigOverrides($storage);
  }

  /**
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   */
  public function testBlankPointerFallsBackToDefaultKey(): void {
    $url = 'https://example.search.windows.net';
    $override = $this->buildOverride(
      ['azure_search_url_key' => ''],
      [YsBeaconConfigOverrides::DEFAULT_URL_KEY => $url],
    );

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertSame($url, $overrides[self::VDB_CONFIG]['url']);
  }

  /**
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   */
  public function testMissingPointerKeyFallsBackToDefaultKey(): void {
    $url = 'https://example.search.windows.net';
    $override = $this->buildOverride(
      [],
      [YsBeaconConfigOverrides::DEFAULT_URL_KEY => $url],
    );

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertSame($url, $overrides[self::VDB_CONFIG]['url']);
  }

  /**
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   */
  public function testExplicitKeyIdIsUsedOverDefault(): void {
    $url = 'https://custom.search.windows.net';
    $override = $this->buildOverride(
      ['azure_search_url_key' => 'custom_url_key'],
      ['custom_url_key' => $url, YsBeaconConfigOverrides::DEFAULT_URL_KEY => 'https://wrong.example'],
    );

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertSame($url, $overrides[self::VDB_CONFIG]['url']);
  }

  /**
   * A scheme-less endpoint key value is normalized to an https URL.
   *
   * A Pantheon secret holding a bare host would otherwise reach Guzzle without
   * a scheme, failing every Azure request (including index creation) with "The
   * scheme "" is not allowed by the protocols request option".
   *
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   * @covers ::normalizeEndpoint
   */
  public function testBareHostEndpointGetsHttpsScheme(): void {
    $override = $this->buildOverride(
      ['azure_search_url_key' => ''],
      [YsBeaconConfigOverrides::DEFAULT_URL_KEY => 'example.search.windows.net'],
    );

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertSame('https://example.search.windows.net', $overrides[self::VDB_CONFIG]['url']);
  }

  /**
   * A protocol-relative endpoint key value is normalized to an https URL.
   *
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   * @covers ::normalizeEndpoint
   */
  public function testProtocolRelativeEndpointGetsHttpsScheme(): void {
    $override = $this->buildOverride(
      ['azure_search_url_key' => ''],
      [YsBeaconConfigOverrides::DEFAULT_URL_KEY => '//example.search.windows.net'],
    );

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertSame('https://example.search.windows.net', $overrides[self::VDB_CONFIG]['url']);
  }

  /**
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   */
  public function testNoUrlOverrideWhenKeyUnavailable(): void {
    $override = $this->buildOverride(['azure_search_url_key' => ''], []);

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertArrayNotHasKey(self::VDB_CONFIG, $overrides);
  }

  /**
   * The VDB override is invalidated when its endpoint-URL key changes.
   *
   * A newly synced Pantheon secret (the key entity) must take effect without a
   * full cache rebuild, so the override's cache metadata must tag the resolved
   * key - the default key for a blank pointer, the explicit key otherwise.
   *
   * @covers ::getCacheableMetadata
   */
  public function testVdbCacheMetadataTagsTheResolvedKey(): void {
    $blank = $this->buildOverride(['azure_search_url_key' => ''], []);
    $tags = $blank->getCacheableMetadata(self::VDB_CONFIG)->getCacheTags();
    $this->assertContains('config:key.key.' . YsBeaconConfigOverrides::DEFAULT_URL_KEY, $tags);
    $this->assertContains('config:ys_beacon.settings', $tags);

    $explicit = $this->buildOverride(['azure_search_url_key' => 'custom_url_key'], []);
    $this->assertContains(
      'config:key.key.custom_url_key',
      $explicit->getCacheableMetadata(self::VDB_CONFIG)->getCacheTags(),
    );
  }

  /**
   * Unrelated config gets no cache tags and is not read.
   *
   * @covers ::getCacheableMetadata
   */
  public function testUnrelatedConfigGetsNoCacheTags(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->expects($this->never())->method('read');
    $service = new YsBeaconConfigOverrides($storage);

    $this->assertSame([], $service->getCacheableMetadata('system.site')->getCacheTags());
  }

  /**
   * The index is left enabled when chat is on, authorized, and index named.
   *
   * @covers ::loadOverrides
   */
  public function testIndexNotForcedOffWhenChatEnabledAndIndexNamed(): void {
    $override = $this->buildOverride(
      ['enable_chat' => TRUE, 'platform_authorized' => TRUE, 'azure_index_name' => 'somesite-live'],
      [],
    );

    $overrides = $override->loadOverrides([self::INDEX_CONFIG]);
    $this->assertArrayNotHasKey(self::INDEX_CONFIG, $overrides);
  }

  /**
   * An unauthorized site has its chat toggle forced off for all consumers.
   *
   * @covers ::loadOverrides
   */
  public function testEnableChatForcedOffWhenNotAuthorized(): void {
    $override = $this->buildOverride(
      ['enable_chat' => TRUE, 'platform_authorized' => FALSE],
      [],
    );

    $overrides = $override->loadOverrides([self::SETTINGS_CONFIG]);
    $this->assertFalse($overrides[self::SETTINGS_CONFIG]['enable_chat']);
  }

  /**
   * An authorized site's saved chat toggle is left untouched.
   *
   * @covers ::loadOverrides
   */
  public function testEnableChatNotOverriddenWhenAuthorized(): void {
    $override = $this->buildOverride(
      ['enable_chat' => TRUE, 'platform_authorized' => TRUE],
      [],
    );

    $overrides = $override->loadOverrides([self::SETTINGS_CONFIG]);
    $this->assertArrayNotHasKey(self::SETTINGS_CONFIG, $overrides);
  }

  /**
   * An unauthorized site forces the index off even with chat on and named.
   *
   * @covers ::loadOverrides
   */
  public function testIndexForcedOffWhenNotAuthorized(): void {
    $override = $this->buildOverride(
      ['enable_chat' => TRUE, 'platform_authorized' => FALSE, 'azure_index_name' => 'somesite-live'],
      [],
    );

    $overrides = $override->loadOverrides([self::INDEX_CONFIG]);
    $this->assertFalse($overrides[self::INDEX_CONFIG]['status']);
  }

  /**
   * Disabling chat forces the index off even when an index name is configured.
   *
   * @covers ::loadOverrides
   */
  public function testIndexForcedOffWhenChatDisabled(): void {
    $override = $this->buildOverride(
      ['enable_chat' => FALSE, 'azure_index_name' => 'somesite-live'],
      [],
    );

    $overrides = $override->loadOverrides([self::INDEX_CONFIG]);
    $this->assertFalse($overrides[self::INDEX_CONFIG]['status']);
  }

  /**
   * The index is forced off when no index name is configured yet.
   *
   * @covers ::loadOverrides
   */
  public function testIndexForcedOffWhenIndexNameEmpty(): void {
    $override = $this->buildOverride(
      ['enable_chat' => TRUE, 'azure_index_name' => ''],
      [],
    );

    $overrides = $override->loadOverrides([self::INDEX_CONFIG]);
    $this->assertFalse($overrides[self::INDEX_CONFIG]['status']);
  }

  /**
   * The index is forced off when chat is off and no index name is set.
   *
   * @covers ::loadOverrides
   */
  public function testIndexForcedOffWhenChatDisabledAndIndexNameEmpty(): void {
    $override = $this->buildOverride(
      ['enable_chat' => FALSE, 'azure_index_name' => ''],
      [],
    );

    $overrides = $override->loadOverrides([self::INDEX_CONFIG]);
    $this->assertFalse($overrides[self::INDEX_CONFIG]['status']);
  }

  /**
   * The read-only flag is no longer applied as a runtime index override.
   *
   * It is persisted onto the index entity by BeaconIndexManager instead, so a
   * read-only site's index config carries no override here and stays enabled so
   * the collection can still be queried.
   *
   * @covers ::loadOverrides
   */
  public function testReadOnlyIsNotOverridden(): void {
    $override = $this->buildOverride(
      [
        'enable_chat' => TRUE,
        'platform_authorized' => TRUE,
        'azure_index_name' => 'other-site-live',
        'read_only' => TRUE,
      ],
      [],
    );

    // Chat is on, authorized, and an index is named, so no status override
    // fires; and read_only is no longer overridden, so the index config is
    // untouched entirely.
    $this->assertArrayNotHasKey(self::INDEX_CONFIG, $override->loadOverrides([self::INDEX_CONFIG]));
  }

  /**
   * The search server config is no longer overridden.
   *
   * The per-site Azure database name is persisted onto search_api.server by
   * BeaconIndexManager, not layered on at runtime, so this class leaves server
   * config alone and its name never even reaches the relevance check.
   *
   * @covers ::loadOverrides
   * @covers ::mayBeRelevant
   */
  public function testServerConfigIsNotOverridden(): void {
    $override = $this->buildOverride(
      ['enable_chat' => TRUE, 'platform_authorized' => TRUE, 'azure_index_name' => 'site-live'],
      [],
    );

    $this->assertSame([], $override->loadOverrides(['search_api.server.ys_beacon']));
  }

  /**
   * An unconfigured site disables the configured index by its real name.
   *
   * @covers ::loadOverrides
   */
  public function testUnconfiguredSiteDisablesConfiguredIndex(): void {
    $overrides = $this->overridesFor([
      'search_index_id' => 'internal',
      'azure_index_name' => '',
    ])->loadOverrides(['search_api.index.internal']);

    $this->assertFalse($overrides['search_api.index.internal']['status'] ?? NULL);
  }

  /**
   * Unrelated config names are skipped without reading settings.
   *
   * @covers ::loadOverrides
   * @covers ::mayBeRelevant
   */
  public function testIrrelevantConfigIsIgnored(): void {
    $storage = $this->createMock(StorageInterface::class);
    // The early-out must not even read ys_beacon.settings for unrelated names.
    $storage->expects($this->never())->method('read');
    $service = new YsBeaconConfigOverrides($storage);

    $this->assertSame([], $service->loadOverrides(['system.site', 'views.view.frontpage']));
  }

  /**
   * Builds an override service backed by the given ys_beacon settings.
   */
  private function overridesFor(array $settings): YsBeaconConfigOverrides {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->with('ys_beacon.settings')->willReturn($settings);
    return new YsBeaconConfigOverrides($storage);
  }

}
