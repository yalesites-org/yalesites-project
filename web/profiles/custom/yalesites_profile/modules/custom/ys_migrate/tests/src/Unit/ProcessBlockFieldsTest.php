<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Plugin\migrate\process\ProcessBlockFields;

/**
 * Tests for the ProcessBlockFields migration process plugin.
 *
 * @group ys_migrate
 * @coversDefaultClass \Drupal\ys_migrate\Plugin\migrate\process\ProcessBlockFields
 */
class ProcessBlockFieldsTest extends UnitTestCase {

  /**
   * The plugin under test.
   *
   * @var \Drupal\ys_migrate\Plugin\migrate\process\ProcessBlockFields
   */
  protected $plugin;

  /**
   * Mock entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mock logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    $this->plugin = new ProcessBlockFields(
      [],
      'process_block_fields',
      [],
      $this->entityFieldManager,
      $this->entityTypeManager,
      $logger_factory
    );
  }

  /**
   * Tests text field processing.
   *
   * @covers ::processFieldValue
   */
  public function testProcessTextField() {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getType')->willReturn('text');

    $result = $this->invokeMethod($this->plugin, 'processFieldValue', [
      'Test text value',
      'text',
      $field_definition,
    ]);

    $this->assertEquals(['value' => 'Test text value'], $result);
  }

  /**
   * Tests text_long field processing.
   *
   * @covers ::processFieldValue
   */
  public function testProcessTextLongField() {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getType')->willReturn('text_long');

    $result = $this->invokeMethod($this->plugin, 'processFieldValue', [
      '<p>Test HTML content</p>',
      'text_long',
      $field_definition,
    ]);

    $expected = [
      'value' => '<p>Test HTML content</p>',
      'format' => 'basic_html',
    ];
    $this->assertEquals($expected, $result);
  }

  /**
   * Tests boolean field processing.
   *
   * @covers ::processFieldValue
   */
  public function testProcessBooleanField() {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getType')->willReturn('boolean');

    $this->assertTrue($this->invokeMethod($this->plugin, 'processFieldValue', [
      1,
      'boolean',
      $field_definition,
    ]));

    $this->assertFalse($this->invokeMethod($this->plugin, 'processFieldValue', [
      0,
      'boolean',
      $field_definition,
    ]));
  }

  /**
   * Tests link field processing.
   *
   * @covers ::processFieldValue
   */
  public function testProcessLinkField() {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getType')->willReturn('link');

    // Test string URL conversion
    $result = $this->invokeMethod($this->plugin, 'processFieldValue', [
      'https://example.com',
      'link',
      $field_definition,
    ]);

    $expected = [
      'uri' => 'https://example.com',
      'title' => '',
    ];
    $this->assertEquals($expected, $result);

    // Test array input passthrough
    $input = [
      'uri' => 'https://example.com/test',
      'title' => 'Test Link',
    ];
    $result = $this->invokeMethod($this->plugin, 'processFieldValue', [
      $input,
      'link',
      $field_definition,
    ]);
    $this->assertEquals($input, $result);
  }

  /**
   * Tests paragraph creation.
   *
   * @covers ::createParagraph
   */
  public function testCreateParagraph() {
    // Mock paragraph storage
    $paragraph_storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('paragraph')
      ->willReturn($paragraph_storage);

    // Mock field definitions for paragraph
    $field_definitions = [
      'field_heading' => $this->createFieldDefinition('text'),
      'field_text' => $this->createFieldDefinition('text_long'),
    ];
    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('paragraph', 'test_paragraph')
      ->willReturn($field_definitions);

    // Mock paragraph entity
    $paragraph = $this->createMock(RevisionableInterface::class);
    $paragraph->method('id')->willReturn(123);
    $paragraph->method('getRevisionId')->willReturn(456);
    $paragraph_storage->method('create')->willReturn($paragraph);

    $fields = [
      'field_heading' => 'Test Heading',
      'field_text' => '<p>Test content</p>',
    ];

    $result = $this->invokeMethod($this->plugin, 'createParagraph', [
      'test_paragraph',
      $fields,
    ]);

    $this->assertSame($paragraph, $result);
  }

  /**
   * Tests entity reference revisions processing.
   *
   * @covers ::processEntityReferenceRevisions
   */
  public function testProcessEntityReferenceRevisions() {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getSetting')
      ->with('handler_settings')
      ->willReturn(['target_bundles' => ['test_paragraph']]);

    // Mock paragraph creation
    $paragraph_storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('paragraph')
      ->willReturn($paragraph_storage);

    $paragraph_field_definitions = [
      'field_heading' => $this->createFieldDefinition('text'),
    ];
    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('paragraph', 'test_paragraph')
      ->willReturn($paragraph_field_definitions);

    $paragraph = $this->createMock(RevisionableInterface::class);
    $paragraph->method('id')->willReturn(123);
    $paragraph->method('getRevisionId')->willReturn(456);
    $paragraph_storage->method('create')->willReturn($paragraph);

    $value = [
      [
        'type' => 'test_paragraph',
        'fields' => [
          'field_heading' => 'Test Heading',
        ],
      ],
    ];

    $result = $this->invokeMethod($this->plugin, 'processEntityReferenceRevisions', [
      $value,
      $field_definition,
    ]);

    $expected = [
      [
        'target_id' => 123,
        'target_revision_id' => 456,
      ],
    ];
    $this->assertEquals($expected, $result);
  }

  /**
   * Tests full field processing integration.
   *
   * @covers ::transform
   */
  public function testTransformIntegration() {
    $configuration = [];
    $plugin_id = 'process_block_fields';
    $plugin_definition = [];

    $row = $this->createMock(Row::class);
    $row->method('getSourceProperty')
      ->willReturnMap([
        ['type', 'accordion'],
        ['fields', [
          'field_heading' => 'Test Accordion',
          'field_accordion_items' => [
            [
              'type' => 'accordion_item',
              'fields' => [
                'field_heading' => 'Test Item',
              ],
            ],
          ],
        ]],
      ]);

    // Mock field definitions for block
    $block_field_definitions = [
      'field_heading' => $this->createFieldDefinition('text'),
      'field_accordion_items' => $this->createFieldDefinition('entity_reference_revisions'),
    ];
    $this->entityFieldManager->method('getFieldDefinitions')
      ->with('block_content', 'accordion')
      ->willReturn($block_field_definitions);

    $migrate_executable = $this->createMock(MigrateExecutableInterface::class);

    $result = $this->plugin->transform([], $migrate_executable, $row, 'test_property');

    $this->assertIsArray($result);
    $this->assertArrayHasKey('field_heading', $result);
    $this->assertEquals(['value' => 'Test Accordion'], $result['field_heading']);
  }

  /**
   * Helper to create a mock field definition.
   */
  protected function createFieldDefinition($type) {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getType')->willReturn($type);
    if ($type === 'entity_reference_revisions') {
      $field_definition->method('getSetting')
        ->with('handler_settings')
        ->willReturn(['target_bundles' => ['accordion_item']]);
    }
    return $field_definition;
  }

  /**
   * Helper to invoke protected/private methods.
   */
  protected function invokeMethod($object, $methodName, array $parameters = []) {
    $reflection = new \ReflectionClass(get_class($object));
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(TRUE);
    return $method->invokeArgs($object, $parameters);
  }

}