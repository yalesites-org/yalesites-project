<?php

namespace Drupal\ys_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Entity\EntityManagerInterface;

/**
 * Source plugin for reading existing D10 block content.
 *
 * @MigrateSource(
 *   id = "d10_block_content"
 * )
 */
class D10BlockContent extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state) {
    // Ensure we use the default database connection, not d7
    $configuration['key'] = 'default';
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('block_content_field_data', 'bcd');
    $query->fields('bcd', [
      'id',
      'type',
      'info',
      'reusable',
      'uuid',
      'revision_id',
    ]);
    
    // Filter by block types if specified
    if (isset($this->configuration['block_types'])) {
      $query->condition('bcd.type', $this->configuration['block_types'], 'IN');
    }
    
    // Only get the latest revision
    $query->condition('bcd.default_langcode', 1);
    
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => 'Block content ID',
      'type' => 'Block content type',
      'info' => 'Administrative label',
      'reusable' => 'Reusable flag',
      'uuid' => 'UUID',
      'revision_id' => 'Revision ID',
      'block_data' => 'Complete block entity data',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
        'alias' => 'bcd',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $block_id = $row->getSourceProperty('id');
    
    // Load the complete block entity to get all field data
    $entity_type_manager = \Drupal::entityTypeManager();
    $block = $entity_type_manager->getStorage('block_content')->load($block_id);
    
    if ($block) {
      // Convert the block entity to an array representation
      $block_data = $this->entityToArray($block);
      $row->setSourceProperty('block_data', $block_data);
    }
    
    return parent::prepareRow($row);
  }

  /**
   * Convert a block entity to array format for field processing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $block
   *   The block content entity.
   *
   * @return array
   *   Array representation of the block's field data.
   */
  protected function entityToArray($block) {
    $data = [];
    $field_definitions = $block->getFieldDefinitions();
    
    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip base fields that aren't configurable
      if (!$field_definition->isDisplayConfigurable('view') && !str_starts_with($field_name, 'field_')) {
        continue;
      }
      
      $field_value = $block->get($field_name);
      if (!$field_value->isEmpty()) {
        $data[$field_name] = $this->extractFieldValue($field_value);
      }
    }
    
    return $data;
  }

  /**
   * Extract field value in a format suitable for processing.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field item list.
   *
   * @return mixed
   *   The extracted field value.
   */
  protected function extractFieldValue($field) {
    $field_type = $field->getFieldDefinition()->getType();
    $values = [];
    
    foreach ($field as $item) {
      switch ($field_type) {
        case 'text_long':
        case 'text_with_summary':
          $values[] = [
            'value' => $item->value,
            'format' => $item->format,
          ];
          break;
          
        case 'link':
          $values[] = [
            'uri' => $item->uri,
            'title' => $item->title,
          ];
          break;
          
        case 'entity_reference_revisions':
          // For paragraph references, get the paragraph data
          if ($item->entity) {
            $values[] = $this->extractParagraphData($item->entity);
          }
          break;
          
        case 'string':
        case 'list_string':
          $values[] = $item->value;
          break;
          
        default:
          $values[] = $item->getValue();
          break;
      }
    }
    
    // Return single value for single-value fields
    return count($values) === 1 ? $values[0] : $values;
  }

  /**
   * Extract paragraph entity data recursively.
   *
   * @param \Drupal\Core\Entity\EntityInterface $paragraph
   *   The paragraph entity.
   *
   * @return array
   *   Array representation of the paragraph.
   */
  protected function extractParagraphData($paragraph) {
    $data = [
      'type' => $paragraph->bundle(),
      'fields' => [],
    ];
    
    $field_definitions = $paragraph->getFieldDefinitions();
    foreach ($field_definitions as $field_name => $field_definition) {
      if (!str_starts_with($field_name, 'field_')) {
        continue;
      }
      
      $field_value = $paragraph->get($field_name);
      if (!$field_value->isEmpty()) {
        $data['fields'][$field_name] = $this->extractFieldValue($field_value);
      }
    }
    
    return $data;
  }

}