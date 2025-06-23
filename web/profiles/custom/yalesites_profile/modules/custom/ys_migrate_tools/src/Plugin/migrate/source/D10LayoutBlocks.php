<?php

namespace Drupal\ys_migrate_tools\Plugin\migrate\source;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Source plugin to extract blocks from a Layout Builder node.
 *
 * This plugin reads a specific node's Layout Builder configuration and
 * extracts all blocks from a target section for migration.
 *
 * @MigrateSource(
 *   id = "d10_layout_blocks",
 *   source_module = "ys_migrate_tools"
 * )
 */
class D10LayoutBlocks extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => $this->t('Block ID'),
      'uuid' => $this->t('Block UUID'),
      'bundle' => $this->t('Block type'),
      'info' => $this->t('Block description'),
      'block_data' => $this->t('Block field data'),
      'component_uuid' => $this->t('Layout component UUID'),
      'region' => $this->t('Layout region'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'component_uuid' => [
        'type' => 'string',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return (string) $this->configuration['source_node'];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $source_node = $this->configuration['source_node'] ?? null;
    $target_section = $this->configuration['target_section'] ?? 'Content Section';

    if (!$source_node) {
      throw new \InvalidArgumentException('source_node must be specified in configuration');
    }

    // Load the source node
    $node = $this->entityTypeManager->getStorage('node')->load($source_node);
    if (!$node || !$node->hasField('layout_builder__layout')) {
      return new \ArrayIterator([]);
    }

    $blocks = [];
    $sections = $node->get('layout_builder__layout')->getSections();

    foreach ($sections as $section) {
      $section_settings = $section->getLayoutSettings();
      $section_label = $section_settings['label'] ?? '';

      // Only extract blocks from the target section
      if ($section_label !== $target_section) {
        continue;
      }

      $components = $section->getComponents();
      foreach ($components as $component) {
        $configuration = $component->get('configuration');
        
        // Only process inline blocks
        if (!isset($configuration['id']) || !str_starts_with($configuration['id'], 'inline_block:')) {
          continue;
        }

        // Extract block type and revision ID
        $block_type = str_replace('inline_block:', '', $configuration['id']);
        $block_revision_id = $configuration['block_revision_id'] ?? null;

        if (!$block_revision_id) {
          continue;
        }

        // Load the block content entity
        $block = $this->entityTypeManager->getStorage('block_content')->loadRevision($block_revision_id);
        if (!$block) {
          continue;
        }

        // Extract block data using the same method as D10BlockContent
        $block_data = $this->entityToArray($block);

        $blocks[] = [
          'id' => $block->id(),
          'uuid' => $block->uuid(),
          'bundle' => $block->bundle(),
          'info' => $block->label(),
          'block_data' => $block_data,
          'component_uuid' => $component->getUuid(),
          'region' => $component->getRegion(),
        ];
      }
    }

    return new \ArrayIterator($blocks);
  }

  /**
   * Convert block entity to array format for migration processing.
   *
   * This mirrors the entityToArray method from D10BlockContent.
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
        $extracted_value = $this->extractFieldValue($field_value);
        // Only include non-empty extracted values
        if (!empty($extracted_value) && $extracted_value !== ['markup' => '']) {
          $data[$field_name] = $extracted_value;
        }
      }
    }

    return $data;
  }

  /**
   * Extract field value in migration-friendly format.
   *
   * This mirrors the extractFieldValue method from D10BlockContent.
   */
  protected function extractFieldValue($field_value) {
    $field_type = $field_value->getFieldDefinition()->getType();
    
    switch ($field_type) {
      case 'text_long':
      case 'text_with_summary':
        $first_item = $field_value->first();
        if ($first_item) {
          return [
            'value' => $first_item->get('value')->getValue(),
            'format' => $first_item->get('format')->getValue(),
          ];
        }
        break;

      case 'string':
      case 'list_string':
        return $field_value->value;

      case 'boolean':
        return (bool) $field_value->value;

      case 'link':
        $first_item = $field_value->first();
        if ($first_item) {
          return [
            'uri' => $first_item->get('uri')->getValue(),
            'title' => $first_item->get('title')->getValue(),
          ];
        }
        break;

      case 'entity_reference_revisions':
        $referenced_entities = [];
        foreach ($field_value as $item) {
          $entity = $item->entity;
          if ($entity) {
            $referenced_entities[] = $this->entityToArray($entity);
          }
        }
        return $referenced_entities;

      default:
        // For other field types, return the raw value
        if ($field_value->count() === 1) {
          return $field_value->value;
        } else {
          $values = [];
          foreach ($field_value as $item) {
            $values[] = $item->value;
          }
          return $values;
        }
    }

    return null;
  }

}