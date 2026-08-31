<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_migrate_sustainability_news\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\ys_migrate_sustainability_news\Plugin\migrate\source\D7NewsImages;

/**
 * Unit tests for the d7_news_images source plugin.
 *
 * GetDatabase() and the module handler are bypassed via reflection, so
 * these tests exercise the plugin's own query-assembly and row-preparation
 * logic without a real database connection or Drupal container.
 *
 * @coversDefaultClass \Drupal\ys_migrate_sustainability_news\Plugin\migrate\source\D7NewsImages
 * @group ys_migrate_sustainability_news
 * @group yalesites
 */
class D7NewsImagesTest extends UnitTestCase {

  /**
   * Builds a plugin instance with mocked migration/state/entity dependencies.
   */
  protected function buildPlugin(): D7NewsImages {
    $id_map = $this->createMock(MigrateIdMapInterface::class);
    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('getIdMap')->willReturn($id_map);
    $migration->method('id')->willReturn('d7_news_images');

    $state = $this->createMock(StateInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $plugin = new D7NewsImages([], 'd7_news_images', [], $migration, $state, $entity_type_manager);
    $plugin->setStringTranslation($this->getStringTranslationStub());

    return $plugin;
  }

  /**
   * Injects a mock database connection.
   *
   * This bypasses getDatabase()'s own connection-resolution logic entirely.
   */
  protected function setDatabase(D7NewsImages $plugin, Connection $database): void {
    $property = new \ReflectionProperty(D7NewsImages::class, 'database');
    $property->setAccessible(TRUE);
    $property->setValue($plugin, $database);
  }

  /**
   * Injects a mock module handler that reports no prepare-row hooks.
   *
   * This lets prepareRow()'s parent::prepareRow() call complete without a
   * container.
   */
  protected function stubModuleHandler(D7NewsImages $plugin): void {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('invokeAll')->willReturn([]);

    $property = new \ReflectionProperty(D7NewsImages::class, 'moduleHandler');
    $property->setAccessible(TRUE);
    $property->setValue($plugin, $module_handler);
  }

  /**
   * Query() assembles the file_managed select with both image field joins.
   *
   * It joins both D7 image field tables, coalesces alt/title across them,
   * and deduplicates by fid.
   *
   * @covers ::query
   */
  public function testQueryBuildsExpectedSelect() {
    $plugin = $this->buildPlugin();

    $fields_call = NULL;
    $conditions = [];
    $order_by = NULL;
    $left_joins = [];
    $expressions = [];
    $group_by = NULL;

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnCallback(function (...$args) use (&$fields_call, $select) {
      $fields_call = $args;
      return $select;
    });
    $select->method('condition')->willReturnCallback(function (...$args) use (&$conditions, $select) {
      $conditions[] = $args;
      return $select;
    });
    $select->method('orderBy')->willReturnCallback(function (...$args) use (&$order_by, $select) {
      $order_by = $args;
      return $select;
    });
    $select->method('leftJoin')->willReturnCallback(function (...$args) use (&$left_joins) {
      $left_joins[] = $args;
      return $args[1];
    });
    $select->method('addExpression')->willReturnCallback(function (...$args) use (&$expressions) {
      $expressions[] = $args;
      return $args[1];
    });
    $select->method('groupBy')->willReturnCallback(function (...$args) use (&$group_by, $select) {
      $group_by = $args;
      return $select;
    });

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('select')
      ->with('file_managed', 'fm', ['fetch' => \PDO::FETCH_ASSOC])
      ->willReturn($select);
    $this->setDatabase($plugin, $database);

    $result = $plugin->query();

    $this->assertSame($select, $result);
    $this->assertEquals(['fm', []], $fields_call);

    $this->assertCount(3, $conditions);
    $this->assertEquals(['fm.uri', 'temporary://%', 'NOT LIKE'], $conditions[0]);
    $this->assertEquals(['fm.uri', 'public://%', 'LIKE'], $conditions[1]);
    $this->assertEquals(
      ['fm.filemime', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'], 'IN'],
      $conditions[2]
    );

    $this->assertEquals(['fm.timestamp', 'ASC'], $order_by);

    $this->assertCount(2, $left_joins);
    $this->assertEquals(['field_data_field_image2', 'fi2', 'fi2.field_image2_fid = fm.fid', []], $left_joins[0]);
    $this->assertEquals(['field_data_field_news_image', 'fni', 'fni.field_news_image_fid = fm.fid', []], $left_joins[1]);

    $this->assertCount(2, $expressions);
    $this->assertEquals(['COALESCE(fi2.field_image2_alt, fni.field_news_image_alt)', 'alt', []], $expressions[0]);
    $this->assertEquals(['COALESCE(fi2.field_image2_title, fni.field_news_image_title)', 'title', []], $expressions[1]);

    $this->assertEquals(['fm.fid'], $group_by);
  }

  /**
   * PrepareRow() normalizes a NULL alt/title to empty strings.
   *
   * @covers ::prepareRow
   */
  public function testPrepareRowNormalizesNullAltAndTitle() {
    $plugin = $this->buildPlugin();
    $this->stubModuleHandler($plugin);

    $row = new Row(['fid' => 1, 'alt' => NULL, 'title' => NULL], ['fid' => ['type' => 'integer']]);
    $result = $plugin->prepareRow($row);

    $this->assertTrue($result);
    $this->assertSame('', $row->getSourceProperty('alt'));
    $this->assertSame('', $row->getSourceProperty('title'));
  }

  /**
   * PrepareRow() leaves a non-NULL alt/title untouched.
   *
   * @covers ::prepareRow
   */
  public function testPrepareRowLeavesExistingAltAndTitleUntouched() {
    $plugin = $this->buildPlugin();
    $this->stubModuleHandler($plugin);

    $row = new Row(
      ['fid' => 1, 'alt' => 'Existing alt', 'title' => 'Existing title'],
      ['fid' => ['type' => 'integer']]
    );
    $plugin->prepareRow($row);

    $this->assertSame('Existing alt', $row->getSourceProperty('alt'));
    $this->assertSame('Existing title', $row->getSourceProperty('title'));
  }

  /**
   * Fields() documents the base file_managed columns plus alt/title.
   *
   * @covers ::fields
   */
  public function testFieldsReturnsExpectedKeys() {
    $plugin = $this->buildPlugin();

    $fields = $plugin->fields();

    $this->assertCount(10, $fields);
    foreach (['fid', 'uid', 'filename', 'uri', 'filemime', 'filesize', 'status', 'timestamp', 'alt', 'title'] as $key) {
      $this->assertArrayHasKey($key, $fields);
    }
  }

  /**
   * GetIds() declares fid as the sole, integer, aliased id field.
   *
   * @covers ::getIds
   */
  public function testGetIdsReturnsFidDefinition() {
    $plugin = $this->buildPlugin();

    $this->assertEquals(['fid' => ['type' => 'integer', 'alias' => 'fm']], $plugin->getIds());
  }

}
