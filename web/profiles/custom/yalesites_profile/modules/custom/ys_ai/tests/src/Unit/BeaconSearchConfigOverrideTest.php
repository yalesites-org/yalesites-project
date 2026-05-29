<?php

namespace Drupal\Tests\ys_ai\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\StorageInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Config\BeaconSearchConfigOverride;

/**
 * @coversDefaultClass \Drupal\ys_ai\Config\BeaconSearchConfigOverride
 *
 * @group yalesites
 */
class BeaconSearchConfigOverrideTest extends UnitTestCase {

  /**
   * Captured original env values restored after each test.
   *
   * @var array
   */
  protected $envBackup = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['PANTHEON_SITE_NAME', 'PANTHEON_ENVIRONMENT', 'LANDO_APP_NAME', 'DDEV_SITENAME'] as $name) {
      $this->envBackup[$name] = $_ENV[$name] ?? NULL;
      unset($_ENV[$name]);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->envBackup as $name => $value) {
      if ($value === NULL) {
        unset($_ENV[$name]);
        putenv($name);
      }
      else {
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
      }
    }
    parent::tearDown();
  }

  /**
   * Builds a key repository that returns the given value for the URL key.
   *
   * @param string|null $value
   *   The value the Key should return, or NULL to simulate a missing Key.
   *
   * @return \Drupal\key\KeyRepositoryInterface
   *   The mocked repository.
   */
  protected function keyRepositoryReturning($value) {
    $repository = $this->createMock(KeyRepositoryInterface::class);
    if ($value === NULL) {
      $repository->method('getKey')->willReturn(NULL);
      return $repository;
    }
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($value);
    $repository->method('getKey')->willReturn($key);
    return $repository;
  }

  /**
   * Builds a config storage mock that returns the given database_name.
   *
   * @param string $databaseName
   *   The stored database_name value (empty string simulates no saved value).
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The mocked storage.
   */
  protected function storageReturning(string $databaseName) {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('read')->willReturn([
      'backend_config' => [
        'database_settings' => [
          'database_name' => $databaseName,
        ],
      ],
    ]);
    return $storage;
  }

  /**
   * @covers ::loadOverrides
   */
  public function testUrlOverrideWhenKeyHasValue(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning('https://example.search.windows.net'),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['ai_vdb_provider_azure_ai_search.settings']);

    $this->assertSame(
      ['ai_vdb_provider_azure_ai_search.settings' => ['url' => 'https://example.search.windows.net']],
      $result
    );
  }

  /**
   * @covers ::loadOverrides
   */
  public function testUrlOverrideTrimsWhitespace(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning("  https://example.search.windows.net\n"),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['ai_vdb_provider_azure_ai_search.settings']);

    $this->assertSame(
      'https://example.search.windows.net',
      $result['ai_vdb_provider_azure_ai_search.settings']['url']
    );
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoUrlOverrideWhenKeyMissing(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['ai_vdb_provider_azure_ai_search.settings']);

    $this->assertArrayNotHasKey('ai_vdb_provider_azure_ai_search.settings', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoUrlOverrideWhenKeyEmpty(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(''),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['ai_vdb_provider_azure_ai_search.settings']);

    $this->assertArrayNotHasKey('ai_vdb_provider_azure_ai_search.settings', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testDatabaseNameDerivedOnPantheon(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'yalehospitality';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertSame(
      'yalehospitality-live',
      $result['search_api.server.beacon']['backend_config']['database_settings']['database_name']
    );
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoDatabaseNameOverrideOutsideAnyKnownEnv(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertArrayNotHasKey('search_api.server.beacon', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoDatabaseNameOverrideWhenEnvironmentMissing(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'yalehospitality';

    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertArrayNotHasKey('search_api.server.beacon', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoDatabaseNameOverrideWhenStoredValuePresent(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'yalehospitality';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('my-custom-index')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertArrayNotHasKey('search_api.server.beacon', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testDatabaseNameDerivedOnLando(): void {
    $_ENV['LANDO_APP_NAME'] = 'yalesites';

    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertSame(
      'yalesites-local',
      $result['search_api.server.beacon']['backend_config']['database_settings']['database_name']
    );
  }

  /**
   * @covers ::loadOverrides
   */
  public function testDatabaseNameDerivedOnDdev(): void {
    $_ENV['DDEV_SITENAME'] = 'yalesites-ddev';

    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertSame(
      'yalesites-ddev-local',
      $result['search_api.server.beacon']['backend_config']['database_settings']['database_name']
    );
  }

  /**
   * @covers ::loadOverrides
   */
  public function testLandoNotUsedWhenStoredValuePresent(): void {
    $_ENV['LANDO_APP_NAME'] = 'yalesites';

    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('my-custom-index')
    );

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertArrayNotHasKey('search_api.server.beacon', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testBothOverridesTogether(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'mysite';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'dev';
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning('https://example.search.windows.net'),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides([
      'ai_vdb_provider_azure_ai_search.settings',
      'search_api.server.beacon',
    ]);

    $this->assertSame('https://example.search.windows.net', $result['ai_vdb_provider_azure_ai_search.settings']['url']);
    $this->assertSame('mysite-dev', $result['search_api.server.beacon']['backend_config']['database_settings']['database_name']);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testUnrelatedConfigNotOverridden(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'mysite';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'dev';
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning('https://example.search.windows.net'),
      $this->storageReturning('')
    );

    $result = $override->loadOverrides(['system.site', 'search_api.server.other']);

    $this->assertSame([], $result);
  }

  /**
   * @covers ::getCacheSuffix
   */
  public function testCacheSuffix(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );
    $this->assertSame('ys_ai_beacon_search', $override->getCacheSuffix());
  }

  /**
   * @covers ::createConfigObject
   */
  public function testCreateConfigObjectReturnsNull(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );
    $this->assertNull($override->createConfigObject('any.name'));
  }

  /**
   * @covers ::getCacheableMetadata
   */
  public function testCacheableMetadataIsEmpty(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning(NULL),
      $this->storageReturning('')
    );
    $metadata = $override->getCacheableMetadata('ai_vdb_provider_azure_ai_search.settings');
    $this->assertInstanceOf(CacheableMetadata::class, $metadata);
    $this->assertSame([], $metadata->getCacheContexts());
    $this->assertSame([], $metadata->getCacheTags());
  }

}
