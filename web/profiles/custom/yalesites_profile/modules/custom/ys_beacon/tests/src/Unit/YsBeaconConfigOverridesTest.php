<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\StorageInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ys_beacon\Config\YsBeaconConfigOverrides;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests the Azure endpoint URL override resolution.
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
   * @covers ::loadOverrides
   * @covers ::getAzureSearchUrl
   */
  public function testNoUrlOverrideWhenKeyUnavailable(): void {
    $override = $this->buildOverride(['azure_search_url_key' => ''], []);

    $overrides = $override->loadOverrides([self::VDB_CONFIG]);
    $this->assertArrayNotHasKey(self::VDB_CONFIG, $overrides);
  }

}
