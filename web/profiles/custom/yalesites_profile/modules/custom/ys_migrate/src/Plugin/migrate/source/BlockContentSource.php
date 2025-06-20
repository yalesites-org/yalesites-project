<?php

namespace Drupal\ys_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\EmbeddedDataSource;
use Drupal\migrate\Row;

/**
 * Generic source plugin for creating block content from YAML configuration.
 *
 * This plugin reads block configuration from the migration YAML and provides
 * a generic way to create any type of block content entity.
 *
 * @MigrateSource(
 *   id = "block_content_source"
 * )
 */
class BlockContentSource extends EmbeddedDataSource {

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => 'Block ID',
      'type' => 'Block type',
      'info' => 'Administrative label',
      'reusable' => 'Reusable flag',
      'fields' => 'Block field values',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'string',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'Block Content Source';
  }

  /**
   * Prepare block configuration data for migration.
   */
  protected function prepareBlockData(array $block_config) {
    // Flatten the block config for easier field access
    $row = [
      'id' => $block_config['id'],
      'type' => $block_config['type'],
      'info' => $block_config['info'] ?? $block_config['id'],
      'reusable' => $block_config['reusable'] ?? 0,
    ];

    // Add field data directly to the row
    foreach ($block_config as $key => $value) {
      if (strpos($key, 'field_') === 0) {
        $row[$key] = $value;
      }
    }

    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration) {
    // Set default ids if not provided
    if (!isset($configuration['ids'])) {
      $configuration['ids'] = [
        'id' => ['type' => 'string']
      ];
    }
    
    // Convert blocks configuration to embedded data format
    if (isset($configuration['blocks'])) {
      $data_rows = [];
      foreach ($configuration['blocks'] as $block_config) {
        $data_rows[] = $this->prepareBlockData($block_config);
      }
      $configuration['data_rows'] = $data_rows;
      unset($configuration['blocks']);
    }
    
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

}