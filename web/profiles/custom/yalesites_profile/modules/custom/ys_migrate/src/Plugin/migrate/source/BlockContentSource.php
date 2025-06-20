<?php

namespace Drupal\ys_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
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
class BlockContentSource extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => $this->t('Block ID'),
      'type' => $this->t('Block type'),
      'info' => $this->t('Administrative label'),
      'reusable' => $this->t('Reusable flag'),
      'fields' => $this->t('Block field values'),
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
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $blocks = $this->configuration['blocks'] ?? [];
    $rows = [];

    foreach ($blocks as $block_config) {
      $rows[] = $this->prepareRow($block_config);
    }

    return new \ArrayIterator($rows);
  }

  /**
   * Prepare a row for migration.
   */
  protected function prepareRow(array $block_config) {
    // Set defaults for block configuration
    $row = [
      'id' => $block_config['id'],
      'type' => $block_config['type'],
      'info' => $block_config['info'] ?? $block_config['id'],
      'reusable' => $block_config['reusable'] ?? 0,
      'fields' => $block_config['fields'] ?? [],
    ];

    return $row;
  }

}