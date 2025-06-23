<?php

namespace Drupal\Tests\ys_migrate_core\Unit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\layout_builder\Section;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate_core\Plugin\migrate\process\ConfigurableLayoutBuilder;

/**
 * Tests for ConfigurableLayoutBuilder process plugin.
 *
 * @group ys_migrate_core
 * @coversDefaultClass \Drupal\ys_migrate_core\Plugin\migrate\process\ConfigurableLayoutBuilder
 */
class ConfigurableLayoutBuilderTest extends UnitTestCase {

  /**
   * The plugin under test.
   *
   * @var \Drupal\ys_migrate_core\Plugin\migrate\process\ConfigurableLayoutBuilder
   */
  protected $plugin;

  /**
   * Mock UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $uuid;

  /**
   * Mock logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * Mock logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mock migrate executable.
   *
   * @var \Drupal\migrate\MigrateExecutableInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $migrateExecutable;

  /**
   * Mock migrate row.
   *
   * @var \Drupal\migrate\Row|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $row;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock dependencies
    $this->uuid = $this->createMock(UuidInterface::class);
    $this->uuid->method('generate')->willReturn('test-uuid-123');

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);

    $this->database = $this->createMock(Connection::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->migrateExecutable = $this->createMock(MigrateExecutableInterface::class);
    $this->row = $this->createMock(Row::class);
  }

  /**
   * Creates a plugin instance with given configuration.
   */
  protected function createPlugin(array $configuration = []): ConfigurableLayoutBuilder {
    return new ConfigurableLayoutBuilder(
      $configuration,
      'configurable_layout_builder',
      [],
      $this->uuid,
      $this->loggerFactory,
      $this->database,
      $this->entityTypeManager
    );
  }

  /**
   * Tests basic section creation without append mode.
   *
   * @covers ::transform
   */
  public function testBasicSectionCreation(): void {
    $configuration = [
      'sections' => [
        [
          'layout' => 'layout_onecol',
          'layout_settings' => ['label' => 'Test Section'],
          'regions' => [
            'content' => [
              [
                'type' => 'text',
                'source' => 'existing',
                'block_id' => 123,
              ],
            ],
          ],
        ],
      ],
    ];

    $plugin = $this->createPlugin($configuration);

    // Mock block entity
    $block = $this->createMock(\Drupal\block_content\BlockContentInterface::class);
    $block->method('label')->willReturn('Test Block');
    $block->method('getRevisionId')->willReturn(456);

    $blockStorage = $this->createMock(EntityStorageInterface::class);
    $blockStorage->method('load')->with(123)->willReturn($block);

    $this->entityTypeManager->method('getStorage')
      ->with('block_content')
      ->willReturn($blockStorage);

    $result = $plugin->transform(1, $this->migrateExecutable, $this->row, 'layout_builder__layout');

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertInstanceOf(Section::class, $result[0]);
    
    $section = $result[0];
    $this->assertEquals('layout_onecol', $section->getLayoutId());
    $this->assertEquals(['label' => 'Test Section'], $section->getLayoutSettings());
    
    $components = $section->getComponents();
    $this->assertCount(1, $components);
  }

  /**
   * Tests Content Section targeting with append mode.
   *
   * @covers ::transform
   * @covers ::appendToContentSection
   */
  public function testContentSectionAppendMode(): void {
    $configuration = [
      'target_section' => 'Content Section',
      'append_mode' => true,
      'sections' => [
        [
          'layout' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'type' => 'text',
                'source' => 'existing',
                'block_id' => 123,
              ],
            ],
          ],
        ],
      ],
    ];

    $plugin = $this->createPlugin($configuration);

    // Mock existing Content Section
    $existingSection = $this->createMock(Section::class);
    $existingSection->method('getLayoutSettings')->willReturn(['label' => 'Content Section']);
    $existingSection->method('getComponents')->willReturn([]);
    $existingSection->expects($this->once())->method('appendComponent');

    // Mock layout field with proper interface
    $layoutField = $this->getMockBuilder(LayoutSectionItemList::class)
      ->disableOriginalConstructor()
      ->getMock();
    $layoutField->method('getSections')->willReturn([$existingSection]);

    // Mock node
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('layout_builder__layout')->willReturn(true);
    $node->method('get')->with('layout_builder__layout')->willReturn($layoutField);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(1)->willReturn($node);

    // Mock block entity
    $block = $this->createMock(\Drupal\block_content\BlockContentInterface::class);
    $block->method('label')->willReturn('Test Block');
    $block->method('getRevisionId')->willReturn(456);

    $blockStorage = $this->createMock(EntityStorageInterface::class);
    $blockStorage->method('load')->with(123)->willReturn($block);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $nodeStorage],
        ['block_content', $blockStorage],
      ]);

    $result = $plugin->transform(1, $this->migrateExecutable, $this->row, 'layout_builder__layout');

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertInstanceOf(Section::class, $result[0]);
  }

  /**
   * Tests Content Section creation when it doesn't exist.
   *
   * @covers ::appendToContentSection
   */
  public function testContentSectionCreation(): void {
    $configuration = [
      'target_section' => 'Content Section',
      'append_mode' => true,
      'sections' => [
        [
          'layout' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'type' => 'text',
                'source' => 'existing',
                'block_id' => 123,
              ],
            ],
          ],
        ],
      ],
    ];

    $plugin = $this->createPlugin($configuration);

    // Mock existing section (NOT Content Section)
    $existingSection = $this->createMock(Section::class);
    $existingSection->method('getLayoutSettings')->willReturn(['label' => 'Banner Section']);

    // Mock layout field with proper interface
    $layoutField = $this->getMockBuilder(LayoutSectionItemList::class)
      ->disableOriginalConstructor()
      ->getMock();
    $layoutField->method('getSections')->willReturn([$existingSection]);

    // Mock node
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('layout_builder__layout')->willReturn(true);
    $node->method('get')->with('layout_builder__layout')->willReturn($layoutField);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(1)->willReturn($node);

    // Mock block entity
    $block = $this->createMock(\Drupal\block_content\BlockContentInterface::class);
    $block->method('label')->willReturn('Test Block');
    $block->method('getRevisionId')->willReturn(456);

    $blockStorage = $this->createMock(EntityStorageInterface::class);
    $blockStorage->method('load')->with(123)->willReturn($block);

    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['node', $nodeStorage],
        ['block_content', $blockStorage],
      ]);

    $this->logger->expects($this->once())
      ->method('info')
      ->with('Created new Content Section with @count blocks in node @id', [
        '@count' => 1,
        '@id' => 1,
      ]);

    $result = $plugin->transform(1, $this->migrateExecutable, $this->row, 'layout_builder__layout');

    $this->assertIsArray($result);
    $this->assertCount(2, $result); // Original section + new Content Section
    
    // Check that the new Content Section was added
    $contentSection = $result[1];
    $this->assertInstanceOf(Section::class, $contentSection);
    $this->assertEquals('layout_onecol', $contentSection->getLayoutId());
    $this->assertEquals(['label' => 'Content Section'], $contentSection->getLayoutSettings());
  }

  /**
   * Tests handling of nodes without Layout Builder.
   *
   * @covers ::appendToContentSection
   */
  public function testNodeWithoutLayoutBuilder(): void {
    $configuration = [
      'target_section' => 'Content Section',
      'append_mode' => true,
      'sections' => [],
    ];

    $plugin = $this->createPlugin($configuration);

    // Mock node without layout builder
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('layout_builder__layout')->willReturn(false);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with(1)->willReturn($node);

    $this->entityTypeManager->method('getStorage')
      ->with('node')
      ->willReturn($nodeStorage);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with('Node @id does not have layout builder enabled', ['@id' => 1]);

    $result = $plugin->transform(1, $this->migrateExecutable, $this->row, 'layout_builder__layout');

    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests default configuration values.
   *
   * @covers ::transform
   */
  public function testDefaultConfiguration(): void {
    $plugin = $this->createPlugin([]);

    // Should use default createDefaultSection behavior when no sections configured
    $result = $plugin->transform(0, $this->migrateExecutable, $this->row, 'layout_builder__layout');

    $this->assertIsArray($result);
    $this->assertEmpty($result); // Should return empty array for invalid node ID
  }

}