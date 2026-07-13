<?php

namespace Drupal\Tests\ys_campus_groups\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\DataParserPluginManager;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_campus_groups\Plugin\migrate\source\CampusGroupUrl;

/**
 * Unit tests for the CampusGroupUrl migrate source plugin.
 *
 * @coversDefaultClass \Drupal\ys_campus_groups\Plugin\migrate\source\CampusGroupUrl
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class CampusGroupUrlTest extends UnitTestCase {

  /**
   * The plugin instantiates and builds its source URL from configuration.
   *
   * Regression coverage for the hotfix: CampusGroupUrl::__construct() must
   * accept and forward migrate_plus Url::__construct()'s fifth argument
   * ($parserPluginManager). Before the fix every instantiation threw a fatal
   * ArgumentCountError, so the campus_groups_events migration could not run.
   *
   * @covers ::__construct
   */
  public function testConstructShouldSucceedWithConfiguredEndpoint(): void {
    // The group ID is a fabricated placeholder, never a real production value.
    $config_factory = $this->getConfigFactoryStub([
      'ys_campus_groups.settings' => [
        'campus_groups_endpoint' => 'https://example.com/events',
        'campus_groups_api_key' => 'campus_groups_key',
        'campus_groups_groupids' => '12345',
      ],
    ]);

    // No key is resolved, so no auth header is added -- keeps this test focused
    // on constructor argument forwarding.
    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    $key_repository->method('getKey')->willReturn(NULL);

    // Url::toString() resolves external URLs through this service.
    $assembler = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $assembler->method('assemble')->willReturnCallback(
      function ($uri, array $options = []) {
        return $uri . '?' . http_build_query($options['query'] ?? []);
      }
    );

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('key.repository', $key_repository);
    $container->set('unrouted_url_assembler', $assembler);
    \Drupal::setContainer($container);

    $migration = $this->createMock(MigrationInterface::class);
    $parser_plugin_manager = $this->createMock(DataParserPluginManager::class);

    $plugin = new CampusGroupUrl(
      ['urls' => 'test.xml', 'fields' => [], 'ids' => []],
      'campus_groups_url',
      [],
      $migration,
      $parser_plugin_manager
    );

    $this->assertInstanceOf(CampusGroupUrl::class, $plugin);

    // The configured endpoint and query parameters drive the source URL
    // (Url::__toString() returns the built source URLs).
    $source_url = (string) $plugin;
    $this->assertStringContainsString('https://example.com/events', $source_url);
    $this->assertStringContainsString('future_day_range=365', $source_url);
    $this->assertStringContainsString('group_ids=12345', $source_url);
  }

  /**
   * A resolved API key is injected as the x-cg-api-secret request header.
   *
   * Covers the constructor's key-present branch: the campus_groups_events
   * migration authenticates to the Campus Groups API via this header, so a
   * regression that dropped or renamed it would otherwise pass unnoticed.
   *
   * @covers ::__construct
   */
  public function testConstructInjectsApiKeyHeaderWhenKeyPresent(): void {
    // The group ID is a fabricated placeholder, never a real production value.
    $config_factory = $this->getConfigFactoryStub([
      'ys_campus_groups.settings' => [
        'campus_groups_endpoint' => 'https://example.com/events',
        'campus_groups_api_key' => 'campus_groups_key',
        'campus_groups_groupids' => '12345',
      ],
    ]);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('secret-value');
    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    $key_repository->method('getKey')->with('campus_groups_key')->willReturn($key);

    $assembler = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $assembler->method('assemble')->willReturnCallback(
      function ($uri, array $options = []) {
        return $uri . '?' . http_build_query($options['query'] ?? []);
      }
    );

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('key.repository', $key_repository);
    $container->set('unrouted_url_assembler', $assembler);
    \Drupal::setContainer($container);

    $migration = $this->createMock(MigrationInterface::class);
    $parser_plugin_manager = $this->createMock(DataParserPluginManager::class);

    $plugin = new CampusGroupUrl(
      ['urls' => 'test.xml', 'fields' => [], 'ids' => []],
      'campus_groups_url',
      [],
      $migration,
      $parser_plugin_manager
    );

    $property = new \ReflectionProperty($plugin, 'configuration');
    $property->setAccessible(TRUE);
    $configuration = $property->getValue($plugin);

    $this->assertSame('secret-value', $configuration['headers']['x-cg-api-secret']);
  }

  /**
   * GetApiKeyFromKeysModule() returns the key value when the key exists.
   *
   * Constructed via reflection so the leaf key-lookup logic can be exercised
   * without standing up the full migrate source plugin.
   *
   * @covers ::getApiKeyFromKeysModule
   */
  public function testGetApiKeyFromKeysModuleReturnsValueWhenKeyExists(): void {
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('secret-value');

    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    $key_repository->method('getKey')->with('campus_groups_key')->willReturn($key);

    $container = new ContainerBuilder();
    $container->set('key.repository', $key_repository);
    \Drupal::setContainer($container);

    $plugin = (new \ReflectionClass(CampusGroupUrl::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($plugin, 'getApiKeyFromKeysModule');
    $method->setAccessible(TRUE);

    $this->assertSame('secret-value', $method->invoke($plugin, 'campus_groups_key'));
  }

  /**
   * GetApiKeyFromKeysModule() returns NULL when the key does not exist.
   *
   * @covers ::getApiKeyFromKeysModule
   */
  public function testGetApiKeyFromKeysModuleReturnsNullWhenKeyMissing(): void {
    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    $key_repository->method('getKey')->with('missing_key')->willReturn(NULL);

    $container = new ContainerBuilder();
    $container->set('key.repository', $key_repository);
    \Drupal::setContainer($container);

    $plugin = (new \ReflectionClass(CampusGroupUrl::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($plugin, 'getApiKeyFromKeysModule');
    $method->setAccessible(TRUE);

    $this->assertNull($method->invoke($plugin, 'missing_key'));
  }

}
