<?php

namespace Drupal\Tests\ys_ai\Unit;

use Drupal\Core\Cache\CacheableMetadata;
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
    foreach (['PANTHEON_SITE_NAME', 'PANTHEON_ENVIRONMENT'] as $name) {
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
   * @covers ::loadOverrides
   */
  public function testUrlOverrideWhenKeyHasValue(): void {
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning('https://example.search.windows.net')
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
      $this->keyRepositoryReturning("  https://example.search.windows.net\n")
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
    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));

    $result = $override->loadOverrides(['ai_vdb_provider_azure_ai_search.settings']);

    $this->assertArrayNotHasKey('ai_vdb_provider_azure_ai_search.settings', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoUrlOverrideWhenKeyEmpty(): void {
    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(''));

    $result = $override->loadOverrides(['ai_vdb_provider_azure_ai_search.settings']);

    $this->assertArrayNotHasKey('ai_vdb_provider_azure_ai_search.settings', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testDatabaseNameDerivedOnPantheon(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'yalehospitality';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';

    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertSame(
      'yalehospitalitylive',
      $result['search_api.server.beacon']['backend_config']['database_settings']['database_name']
    );
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoDatabaseNameOverrideOutsidePantheon(): void {
    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));

    $result = $override->loadOverrides(['search_api.server.beacon']);

    $this->assertArrayNotHasKey('search_api.server.beacon', $result);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testNoDatabaseNameOverrideWhenEnvironmentMissing(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'yalehospitality';

    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));

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
      $this->keyRepositoryReturning('https://example.search.windows.net')
    );

    $result = $override->loadOverrides([
      'ai_vdb_provider_azure_ai_search.settings',
      'search_api.server.beacon',
    ]);

    $this->assertSame('https://example.search.windows.net', $result['ai_vdb_provider_azure_ai_search.settings']['url']);
    $this->assertSame('mysitedev', $result['search_api.server.beacon']['backend_config']['database_settings']['database_name']);
  }

  /**
   * @covers ::loadOverrides
   */
  public function testUnrelatedConfigNotOverridden(): void {
    $_ENV['PANTHEON_SITE_NAME'] = 'mysite';
    $_ENV['PANTHEON_ENVIRONMENT'] = 'dev';
    $override = new BeaconSearchConfigOverride(
      $this->keyRepositoryReturning('https://example.search.windows.net')
    );

    $result = $override->loadOverrides(['system.site', 'search_api.server.other']);

    $this->assertSame([], $result);
  }

  /**
   * @covers ::getCacheSuffix
   */
  public function testCacheSuffix(): void {
    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));
    $this->assertSame('ys_ai_beacon_search', $override->getCacheSuffix());
  }

  /**
   * @covers ::createConfigObject
   */
  public function testCreateConfigObjectReturnsNull(): void {
    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));
    $this->assertNull($override->createConfigObject('any.name'));
  }

  /**
   * @covers ::getCacheableMetadata
   */
  public function testCacheableMetadataIsEmpty(): void {
    $override = new BeaconSearchConfigOverride($this->keyRepositoryReturning(NULL));
    $metadata = $override->getCacheableMetadata('ai_vdb_provider_azure_ai_search.settings');
    $this->assertInstanceOf(CacheableMetadata::class, $metadata);
    $this->assertSame([], $metadata->getCacheContexts());
    $this->assertSame([], $metadata->getCacheTags());
  }

}
