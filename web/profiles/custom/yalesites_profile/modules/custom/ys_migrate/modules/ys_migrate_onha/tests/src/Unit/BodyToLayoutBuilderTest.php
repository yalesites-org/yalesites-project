<?php

namespace Drupal\Tests\ys_migrate_onha\Unit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\block_content\BlockContentInterface;
use Drupal\layout_builder\Section;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\ys_migrate_onha\Plugin\migrate\process\onha\BodyToLayoutBuilder;

/**
 * Unit tests for the onha_body_to_layout_builder process plugin.
 *
 * @coversDefaultClass \Drupal\ys_migrate_onha\Plugin\migrate\process\onha\BodyToLayoutBuilder
 * @group ys_migrate_onha
 * @group yalesites
 */
class BodyToLayoutBuilderTest extends UnitTestCase {

  /**
   * The uuid service mock.
   *
   * @var \Drupal\Component\Uuid\UuidInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $uuid;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerChannel;

  /**
   * The database connection mock.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The plugin under test.
   *
   * @var \Drupal\ys_migrate_onha\Plugin\migrate\process\onha\BodyToLayoutBuilder
   */
  protected $plugin;

  /**
   * The migrate executable mock, unused by the plugin but required by type.
   *
   * @var \Drupal\migrate\MigrateExecutableInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $migrateExecutable;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->uuid = $this->createMock(UuidInterface::class);
    $this->loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('migrate')->willReturn($this->loggerChannel);
    $this->database = $this->createMock(Connection::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->migrateExecutable = $this->createMock(MigrateExecutableInterface::class);

    $this->plugin = new BodyToLayoutBuilder(
      [],
      'onha_body_to_layout_builder',
      [],
      $this->uuid,
      $logger_factory,
      $this->database,
      $this->entityTypeManager
    );
  }

  /**
   * Builds a mock migrate_map select query returning $fetch_field_result.
   */
  protected function mockBodyBlockQuery($fetch_field_result): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($fetch_field_result);

    $select = $this->createMock(SelectInterface::class);
    $select->expects($this->once())->method('fields')->with('m', ['destid1'])->willReturnSelf();
    $select->expects($this->once())->method('condition')->with('sourceid1', 42)->willReturnSelf();
    $select->expects($this->once())->method('range')->with(0, 1)->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->with('migrate_map_ys_onha_program_body', 'm')
      ->willReturn($select);
  }

  /**
   * A non-numeric source value is rejected without querying the database.
   *
   * @covers ::transform
   */
  public function testTransformWithNonNumericValue() {
    $this->database->expects($this->never())->method('select');
    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with('Invalid node ID: @id', ['@id' => 'abc']);

    $result = $this->plugin->transform('abc', $this->migrateExecutable, new Row(), 'layout_builder__layout');

    $this->assertSame([], $result);
  }

  /**
   * A zero or negative source value is rejected without querying the database.
   *
   * @covers ::transform
   */
  public function testTransformWithNonPositiveValue() {
    $this->database->expects($this->never())->method('select');
    $this->loggerChannel->expects($this->once())
      ->method('error')
      ->with('Invalid node ID: @id', ['@id' => 0]);

    $result = $this->plugin->transform(0, $this->migrateExecutable, new Row(), 'layout_builder__layout');

    $this->assertSame([], $result);
  }

  /**
   * No migrated body block for the node id returns an empty array.
   *
   * @covers ::transform
   * @covers ::getBodyBlockId
   */
  public function testTransformWithNoMigratedBodyBlock() {
    $this->mockBodyBlockQuery(FALSE);
    $this->entityTypeManager->expects($this->never())->method('getStorage');
    $this->loggerChannel->expects($this->once())
      ->method('notice')
      ->with('Could not load block @id', ['@id' => FALSE]);

    $result = $this->plugin->transform(42, $this->migrateExecutable, new Row(), 'layout_builder__layout');

    $this->assertSame([], $result);
  }

  /**
   * A migrated body block is wrapped in a one-column Layout Builder section.
   *
   * @covers ::transform
   * @covers ::getBodyBlockId
   */
  public function testTransformCreatesSectionWhenBodyBlockExists() {
    $this->mockBodyBlockQuery('55');

    $block = $this->createMock(BlockContentInterface::class);
    $block->method('label')->willReturn('Program Body');
    $block->method('getRevisionId')->willReturn(101);

    $block_storage = $this->createMock(EntityStorageInterface::class);
    $block_storage->expects($this->once())->method('load')->with('55')->willReturn($block);
    $this->entityTypeManager->method('getStorage')->with('block_content')->willReturn($block_storage);

    $this->uuid->method('generate')->willReturn('uuid-abc');

    $result = $this->plugin->transform(42, $this->migrateExecutable, new Row(), 'layout_builder__layout');

    $this->assertInstanceOf(Section::class, $result);
    $this->assertEquals('layout_onecol', $result->getLayoutId());

    $components = $result->getComponents();
    $this->assertCount(1, $components);
    $component = reset($components);
    $this->assertEquals('uuid-abc', $component->getUuid());
    // SectionComponent::get() only reads real object properties or the
    // "additional" array, not the plugin configuration -- toArray() is the
    // public way to inspect id/label/provider/etc.
    $configuration = $component->toArray()['configuration'];
    $this->assertEquals('inline_block:text', $configuration['id']);
    $this->assertEquals('Program Body', $configuration['label']);
    $this->assertFalse($configuration['label_display']);
    $this->assertEquals('full', $configuration['view_mode']);
    $this->assertEquals('layout_builder', $configuration['provider']);
    $this->assertEquals(101, $configuration['block_revision_id']);
    $this->assertEquals([], $configuration['context_mapping']);
  }

}
