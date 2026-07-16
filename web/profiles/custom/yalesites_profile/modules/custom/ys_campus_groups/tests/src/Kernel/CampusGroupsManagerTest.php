<?php

namespace Drupal\Tests\ys_campus_groups\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\ys_campus_groups\CampusGroupsManager;

/**
 * Kernel tests for the CampusGroupsManager service.
 *
 * These exercise runMigration() and getMigrationStatus() against the real
 * campus_groups_taxonomy migration, which -- unlike campus_groups_events --
 * uses the embedded_data source plugin and needs no live HTTP endpoint, so
 * MigrateExecutable can actually run end to end here.
 *
 * @group ys_campus_groups
 * @group yalesites
 */
class CampusGroupsManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'taxonomy',
    'migrate',
    'migrate_plus',
    'migrate_tools',
    'key',
    'ys_campus_groups',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installSchema('migrate_tools', ['migrate_tools_sync_source_ids']);
    Vocabulary::create(['vid' => 'event_sources', 'name' => 'Event Sources'])->save();
  }

  /**
   * Builds the manager under test via the container.
   */
  protected function createManager(): CampusGroupsManager {
    return CampusGroupsManager::create($this->container);
  }

  /**
   * @covers ::runMigration
   * @covers ::getMigrationStatus
   */
  public function testRunMigrationImportsCampusGroupsTaxonomy(): void {
    $manager = $this->createManager();
    $manager->runMigration('campus_groups_taxonomy');

    $this->assertSame(1, $manager->getMigrationStatus('campus_groups_taxonomy'));

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['name' => 'Campus Groups', 'vid' => 'event_sources']);
    $this->assertCount(1, $terms);
  }

  /**
   * RunMigration() resets a migration stuck in a non-idle status.
   *
   * A migration left in STATUS_IMPORTING (e.g. from a crashed prior run)
   * would otherwise make MigrateExecutable::import() refuse to run; the
   * status-normalization check in runMigration() resets it to IDLE first.
   *
   * @covers ::runMigration
   */
  public function testRunMigrationResetsNonIdleStatusBeforeImporting(): void {
    $migration_manager = \Drupal::service('plugin.manager.migration');
    $migration = $migration_manager->createInstance('campus_groups_taxonomy');
    $migration->setStatus(MigrationInterface::STATUS_IMPORTING);

    $manager = $this->createManager();
    $manager->runMigration('campus_groups_taxonomy');

    $this->assertSame(MigrationInterface::STATUS_IDLE, $migration->getStatus());
    $this->assertSame(1, $manager->getMigrationStatus('campus_groups_taxonomy'));
  }

  /**
   * @covers ::create
   */
  public function testCreateReturnsCampusGroupsManagerInstance(): void {
    $this->assertInstanceOf(CampusGroupsManager::class, $this->createManager());
  }

}
