<?php

namespace Drupal\Tests\ys_campus_groups\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\migrate\Plugin\MigrationInterface;
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
   * Puts a mocked 'ys_campus_groups.settings' config into the container.
   *
   * @param array $values
   *   Config key/value pairs returned by Config::get().
   */
  protected function setConfig(array $values): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(function ($key) use ($values) {
      return $values[$key] ?? NULL;
    });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')
      ->with('ys_campus_groups.settings')
      ->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    \Drupal::setContainer($container);
  }

  /**
   * Locks in the current constructor behavior for the GAP.
   *
   * Paired with testConstructShouldSucceedWithConfiguredEndpoint() -- delete
   * once the GAP is fixed.
   *
   * CampusGroupUrl::__construct() calls
   * parent::__construct($configuration, $plugin_id, $plugin_definition,
   * $migration) with only four arguments, but migrate_plus's
   * Url::__construct() requires a fifth, non-default $parserPluginManager
   * argument. PHP throws an ArgumentCountError as soon as the parent
   * constructor is invoked -- before any of its body runs -- so every
   * CampusGroupUrl instantiation fails and the campus_groups_events
   * migration can never execute.
   *
   * @covers ::__construct
   */
  public function testConstructThrowsArgumentCountErrorCurrentBehavior(): void {
    $this->setConfig(['campus_groups_endpoint' => NULL]);
    $migration = $this->createMock(MigrationInterface::class);

    $this->expectException(\ArgumentCountError::class);

    new CampusGroupUrl(
      ['urls' => 'test.xml', 'fields' => [], 'ids' => []],
      'campus_groups_url',
      [],
      $migration
    );
  }

  /**
   * Paired with testConstructThrowsArgumentCountErrorCurrentBehavior().
   *
   * GAP: CampusGroupUrl::__construct() never passes the required
   * $parserPluginManager argument through to migrate_plus's
   * Url::__construct(), so instantiating the source plugin always throws a
   * fatal ArgumentCountError and the campus_groups_events migration cannot
   * run.
   *
   * @covers ::__construct
   */
  public function testConstructShouldSucceedWithConfiguredEndpoint(): void {
    $this->markTestSkipped('GAP: CampusGroupUrl::__construct() calls parent::__construct() with only 4 of the 5 arguments required by migrate_plus\'s Url::__construct() (missing $parserPluginManager), so every instantiation -- and therefore the campus_groups_events migration -- throws a fatal ArgumentCountError -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_campus_groups.md');
  }

  /**
   * GetApiKeyFromKeysModule() returns the key value when the key exists.
   *
   * Constructed via reflection without invoking the (broken) constructor, so
   * this leaf logic can be exercised despite the GAP above.
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
