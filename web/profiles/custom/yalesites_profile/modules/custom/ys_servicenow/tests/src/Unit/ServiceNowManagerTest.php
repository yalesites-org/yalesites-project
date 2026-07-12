<?php

namespace Drupal\Tests\ys_servicenow\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\ys_servicenow\ServiceNowManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for the ServiceNowManager service.
 *
 * @coversDefaultClass \Drupal\ys_servicenow\ServiceNowManager
 *
 * @group yalesites
 * @group ys_servicenow
 */
class ServiceNowManagerTest extends UnitTestCase {

  /**
   * Builds a real ServiceNowManager with mocked constructor dependencies.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManager|null $migration_manager
   *   An optional migration manager mock to use in place of a bare one.
   */
  protected function createManager(?MigrationPluginManager $migration_manager = NULL): ServiceNowManager {
    return new ServiceNowManager(
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(Client::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $migration_manager ?? $this->createMock(MigrationPluginManager::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(MessengerInterface::class)
    );
  }

  /**
   * @covers ::getMigrationStatus
   */
  public function testGetMigrationStatusReturnsImportedCount() {
    $id_map = $this->createMock(MigrateIdMapInterface::class);
    $id_map->method('importedCount')->willReturn(5);

    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('getIdMap')->willReturn($id_map);

    $migration_manager = $this->createMock(MigrationPluginManager::class);
    $migration_manager->method('createInstance')
      ->with('servicenow_knowledge_base_articles')
      ->willReturn($migration);

    $manager = $this->createManager($migration_manager);

    $this->assertSame(5, $manager->getMigrationStatus('servicenow_knowledge_base_articles'));
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsAggregatesImportedCountsPerMigration() {
    $manager = $this->getMockBuilder(ServiceNowManager::class)
      ->setConstructorArgs([
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(Client::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(MigrationPluginManager::class),
        $this->createMock(ModuleHandlerInterface::class),
        $this->createMock(TimeInterface::class),
        $this->createMock(MessengerInterface::class),
      ])
      ->onlyMethods(['runMigration', 'getMigrationStatus'])
      ->getMock();

    $ran_migrations = [];
    $manager->method('runMigration')->willReturnCallback(function ($migration) use (&$ran_migrations) {
      $ran_migrations[] = $migration;
    });
    $manager->method('getMigrationStatus')->willReturnMap([
      ['servicenow_knowledge_base_article_block', 3],
      ['servicenow_knowledge_base_articles', 9],
    ]);

    $result = $manager->runAllMigrations();

    // Both migrations are run, in the order declared by the class constant.
    $this->assertSame(ServiceNowManager::SERVICENOW_MIGRATIONS, $ran_migrations);
    $this->assertEquals([
      'servicenow_knowledge_base_article_block' => ['imported' => 3],
      'servicenow_knowledge_base_articles' => ['imported' => 9],
    ], $result);
  }

  /**
   * Current behavior: create() throws a TypeError due to a service ID bug.
   *
   * ServiceNowManager::create() wires the "date.formatter" service (which
   * implements DateFormatterInterface, not TimeInterface) into the
   * constructor's $time argument, which is typed TimeInterface. This is
   * inconsistent with ys_servicenow.services.yml, which correctly wires
   * "datetime.time" for that same argument when the service is built via the
   * container directly. Any code path that instantiates ServiceNowManager
   * via ContainerInjectionInterface::create() (rather than via the
   * ys_servicenow.manager service definition) hits this fatal TypeError.
   * Paired with testCreateShouldInjectDatetimeTimeService() -- delete once
   * the GAP is fixed.
   */
  public function testCreateThrowsTypeErrorForMismatchedTimeService() {
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->createMock(ConfigFactoryInterface::class));
    $container->set('http_client', $this->createMock(Client::class));
    $container->set('entity_type.manager', $this->createMock(EntityTypeManagerInterface::class));
    $container->set('plugin.manager.migration', $this->createMock(MigrationPluginManager::class));
    $container->set('module_handler', $this->createMock(ModuleHandlerInterface::class));
    $container->set('date.formatter', $this->createMock(DateFormatterInterface::class));
    $container->set('messenger', $this->createMock(MessengerInterface::class));

    $this->expectException(\TypeError::class);
    ServiceNowManager::create($container);
  }

  /**
   * GAP test: create() should wire "datetime.time" for the $time argument.
   */
  public function testCreateShouldInjectDatetimeTimeService() {
    $this->markTestSkipped('GAP: ServiceNowManager::create() wires the "date.formatter" service into the TimeInterface-typed $time constructor argument instead of "datetime.time" -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_servicenow.md');
  }

}
