<?php

namespace Drupal\Tests\ys_migrate\Unit;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_migrate\Plugin\migrate\source\BlockContentSource;

/**
 * Tests for the BlockContentSource migration source plugin.
 *
 * @group ys_migrate
 * @coversDefaultClass \Drupal\ys_migrate\Plugin\migrate\source\BlockContentSource
 */
class BlockContentSourceTest extends UnitTestCase {

  /**
   * Tests the source plugin initialization and data processing.
   *
   * @covers ::fields
   * @covers ::getIds
   * @covers ::initializeIterator
   * @covers ::prepareBlockData
   */
  public function testSourcePlugin() {
    $configuration = [
      'blocks' => [
        [
          'id' => 'test_block_1',
          'type' => 'text',
          'info' => 'Test Text Block',
          'reusable' => 0,
          'fields' => [
            'field_text' => [
              'value' => 'Test content',
              'format' => 'basic_html',
            ],
          ],
        ],
        [
          'id' => 'test_block_2',
          'type' => 'accordion',
          'info' => 'Test Accordion',
          'fields' => [
            'field_heading' => 'Test Accordion Heading',
            'field_accordion_items' => [
              [
                'type' => 'accordion_item',
                'fields' => [
                  'field_heading' => 'Item 1',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $migration = $this->createMock(MigrationInterface::class);
    $plugin = new BlockContentSource($configuration, 'block_content_source', [], $migration);

    // Test field definitions
    $fields = $plugin->fields();
    $expected_fields = [
      'id' => 'Block ID',
      'type' => 'Block type',
      'info' => 'Administrative label',
      'reusable' => 'Reusable flag',
      'fields' => 'Block field values',
    ];

    foreach ($expected_fields as $key => $label) {
      $this->assertArrayHasKey($key, $fields);
    }

    // Test ID definitions
    $ids = $plugin->getIds();
    $this->assertArrayHasKey('id', $ids);
    $this->assertEquals('string', $ids['id']['type']);

    // Test iterator using reflection
    $reflection = new \ReflectionClass($plugin);
    $method = $reflection->getMethod('getIterator');
    $method->setAccessible(TRUE);
    $iterator = $method->invoke($plugin);
    $this->assertInstanceOf(\Iterator::class, $iterator);

    $rows = iterator_to_array($iterator);
    $this->assertCount(2, $rows);

    // Test first row
    $first_row = $rows[0];
    $this->assertEquals('test_block_1', $first_row['id']);
    $this->assertEquals('text', $first_row['type']);
    $this->assertEquals('Test Text Block', $first_row['info']);
    $this->assertEquals(0, $first_row['reusable']);
    $this->assertArrayHasKey('field_text', $first_row['fields']);

    // Test second row with defaults
    $second_row = $rows[1];
    $this->assertEquals('test_block_2', $second_row['id']);
    $this->assertEquals('accordion', $second_row['type']);
    $this->assertEquals('Test Accordion', $second_row['info']);
    $this->assertEquals(0, $second_row['reusable']); // Default value
  }

  /**
   * Tests source plugin with minimal configuration.
   *
   * @covers ::prepareBlockData
   */
  public function testMinimalConfiguration() {
    $configuration = [
      'blocks' => [
        [
          'id' => 'minimal_block',
          'type' => 'text',
        ],
      ],
    ];

    $migration = $this->createMock(MigrationInterface::class);
    $plugin = new BlockContentSource($configuration, 'block_content_source', [], $migration);
    $reflection = new \ReflectionClass($plugin);
    $method = $reflection->getMethod('getIterator');
    $method->setAccessible(TRUE);
    $iterator = $method->invoke($plugin);
    $rows = iterator_to_array($iterator);

    $this->assertCount(1, $rows);
    $row = $rows[0];

    // Test defaults are applied
    $this->assertEquals('minimal_block', $row['id']);
    $this->assertEquals('text', $row['type']);
    $this->assertEquals('minimal_block', $row['info']); // ID used as default info
    $this->assertEquals(0, $row['reusable']); // Default reusable
    $this->assertEquals([], $row['fields']); // Default empty fields
  }

  /**
   * Tests source plugin with no blocks configuration.
   *
   * @covers ::initializeIterator
   */
  public function testEmptyConfiguration() {
    $configuration = [];
    $migration = $this->createMock(MigrationInterface::class);
    $plugin = new BlockContentSource($configuration, 'block_content_source', [], $migration);
    $reflection = new \ReflectionClass($plugin);
    $method = $reflection->getMethod('getIterator');
    $method->setAccessible(TRUE);
    $iterator = $method->invoke($plugin);
    $rows = iterator_to_array($iterator);

    $this->assertCount(0, $rows);
  }

  /**
   * Tests complex block configuration with nested paragraphs.
   *
   * @covers ::prepareBlockData
   */
  public function testComplexBlockConfiguration() {
    $configuration = [
      'blocks' => [
        [
          'id' => 'complex_accordion',
          'type' => 'accordion',
          'info' => 'Complex Accordion Block',
          'reusable' => 1,
          'fields' => [
            'field_heading' => 'Main Heading',
            'field_style_color' => 'blue',
            'field_accordion_items' => [
              [
                'type' => 'accordion_item',
                'fields' => [
                  'field_heading' => 'Item 1 Heading',
                  'field_content' => [
                    [
                      'type' => 'text',
                      'fields' => [
                        'field_text' => [
                          'value' => '<p>Item 1 content</p>',
                          'format' => 'basic_html',
                        ],
                      ],
                    ],
                  ],
                ],
              ],
              [
                'type' => 'accordion_item',
                'fields' => [
                  'field_heading' => 'Item 2 Heading',
                  'field_content' => [
                    [
                      'type' => 'text',
                      'fields' => [
                        'field_text' => [
                          'value' => '<p>Item 2 content</p>',
                          'format' => 'basic_html',
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $migration = $this->createMock(MigrationInterface::class);
    $plugin = new BlockContentSource($configuration, 'block_content_source', [], $migration);
    $reflection = new \ReflectionClass($plugin);
    $method = $reflection->getMethod('getIterator');
    $method->setAccessible(TRUE);
    $iterator = $method->invoke($plugin);
    $rows = iterator_to_array($iterator);

    $this->assertCount(1, $rows);
    $row = $rows[0];

    $this->assertEquals('complex_accordion', $row['id']);
    $this->assertEquals('accordion', $row['type']);
    $this->assertEquals('Complex Accordion Block', $row['info']);
    $this->assertEquals(1, $row['reusable']);

    // Verify nested field structure is preserved
    $fields = $row['fields'];
    $this->assertEquals('Main Heading', $fields['field_heading']);
    $this->assertEquals('blue', $fields['field_style_color']);
    $this->assertCount(2, $fields['field_accordion_items']);

    $first_item = $fields['field_accordion_items'][0];
    $this->assertEquals('accordion_item', $first_item['type']);
    $this->assertEquals('Item 1 Heading', $first_item['fields']['field_heading']);
    $this->assertCount(1, $first_item['fields']['field_content']);

    $nested_text = $first_item['fields']['field_content'][0];
    $this->assertEquals('text', $nested_text['type']);
    $this->assertEquals('<p>Item 1 content</p>', $nested_text['fields']['field_text']['value']);
  }

  /**
   * Tests string representation.
   *
   * @covers ::__toString
   */
  public function testToString() {
    $migration = $this->createMock(MigrationInterface::class);
    $plugin = new BlockContentSource([], 'block_content_source', [], $migration);
    $this->assertEquals('Block Content Source', (string) $plugin);
  }

}