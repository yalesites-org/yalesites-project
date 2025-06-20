<?php

namespace Drupal\Tests\ys_migrate\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ys_migrate\Plugin\migrate\process\ProcessBlockFields;
use Drupal\migrate\Row;

/**
 * Tests the ProcessBlockFields plugin integration.
 *
 * @group ys_migrate
 */
class ProcessBlockFieldsIntegrationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ys_migrate',
    'block_content',
    'paragraphs',
    'ys_core',
    'filter',
    'text',
    'link'
  ];

  /**
   * Test basic text field processing.
   */
  public function testBasicTextFieldProcessing() {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_field_manager = $this->container->get('entity_field.manager');
    $logger_factory = $this->container->get('logger.factory');

    $plugin = new ProcessBlockFields(
      [],
      'process_block_fields',
      [],
      $entity_field_manager,
      $entity_type_manager,
      $logger_factory
    );

    // Test simple text field processing
    $fields = [
      'field_text' => [
        'value' => '<p>Test content</p>',
        'format' => 'basic_html'
      ],
      'field_style_variation' => 'default'
    ];

    $block_type = 'text';
    $row = new Row(['fields' => $fields, 'type' => $block_type], ['id' => ['type' => 'string']]);

    try {
      $result = $plugin->transform($fields, $this->createMockExecutable(), $row, 'fields');
      
      $this->assertIsArray($result, 'ProcessBlockFields should return an array');
      $this->assertArrayHasKey('field_text', $result, 'Result should contain field_text');
      $this->assertArrayHasKey('field_style_variation', $result, 'Result should contain field_style_variation');
      
      // Log the actual result for debugging
      \Drupal::logger('ys_migrate_test')->info('Field processing result: @result', ['@result' => print_r($result, TRUE)]);
      
    } catch (\Exception $e) {
      $this->fail('ProcessBlockFields threw an exception: ' . $e->getMessage());
    }
  }

  /**
   * Create a mock migration executable.
   */
  private function createMockExecutable() {
    $migration = $this->createMock('\Drupal\migrate\Plugin\MigrationInterface');
    return $this->createMock('\Drupal\migrate\MigrateExecutableInterface');
  }

}