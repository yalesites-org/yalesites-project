<?php

namespace Drupal\ys_file_management\Service;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for tracking Layout Builder block usage and finding parent entities.
 */
class LayoutBuilderUsageTracker {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a LayoutBuilderUsageTracker object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelInterface $logger,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * Finds parent entities that contain a given block in Layout Builder.
   *
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The block content entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Array of parent entities that contain this block.
   */
  public function findParentEntitiesForBlock(BlockContentInterface $block): array {
    $parents = [];
    $block_uuid = $block->uuid();

    if (empty($block_uuid)) {
      return $parents;
    }

    // Get entity types that have layout builder enabled.
    $entity_types_with_lb = $this->getEntityTypesWithLayoutBuilder();

    foreach ($entity_types_with_lb as $entity_type_id) {
      try {
        $found_parents = $this->searchLayoutBuilderField($entity_type_id, $block_uuid);
        $parents = array_merge($parents, $found_parents);
      }
      catch (\Exception $e) {
        $this->logger->error('Error searching @entity_type for block @uuid: @error', [
          '@entity_type' => $entity_type_id,
          '@uuid' => $block_uuid,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $parents;
  }

  /**
   * Gets entity types that have Layout Builder fields.
   *
   * @return array
   *   Array of entity type IDs that have layout builder enabled.
   */
  protected function getEntityTypesWithLayoutBuilder(): array {
    // Common entity types that use Layout Builder.
    // This could be made configurable or dynamically discovered.
    return ['node', 'block_content', 'taxonomy_term'];
  }

  /**
   * Searches a specific entity type's layout builder field for a block UUID.
   *
   * @param string $entity_type_id
   *   The entity type ID to search.
   * @param string $block_uuid
   *   The block UUID to search for.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Array of entities that contain this block.
   */
  protected function searchLayoutBuilderField(string $entity_type_id, string $block_uuid): array {
    $entities = [];

    // Get the storage for this entity type.
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return $entities;
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    // Check if this entity type has a data table.
    $data_table = $entity_type->getDataTable();
    if (!$data_table) {
      return $entities;
    }

    // Build the field table name for layout_builder__layout field.
    $field_table = $entity_type_id . '__layout_builder__layout';

    // Check if the table exists.
    if (!$this->database->schema()->tableExists($field_table)) {
      return $entities;
    }

    try {
      // Query for entities that have this block UUID in their layout.
      // The layout is stored as serialized data, so we use LIKE.
      $query = $this->database->select($field_table, 'lb')
        ->fields('lb', ['entity_id', 'revision_id'])
        ->condition('lb.layout_builder__layout_section', '%' . $this->database->escapeLike($block_uuid) . '%', 'LIKE')
        ->distinct();

      $results = $query->execute()->fetchAll();

      // Load the entities.
      foreach ($results as $result) {
        $entity = $storage->load($result->entity_id);
        if ($entity) {
          $entities[] = $entity;

          // Log for debugging.
          $this->logger->info('Found block @uuid in @entity_type @id', [
            '@uuid' => $block_uuid,
            '@entity_type' => $entity_type_id,
            '@id' => $result->entity_id,
          ]);
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Database query error in searchLayoutBuilderField: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $entities;
  }

  /**
   * Gets a human-readable location description for a block within an entity.
   *
   * This attempts to extract the section/region information from the layout.
   *
   * @param \Drupal\Core\Entity\EntityInterface $parent_entity
   *   The parent entity containing the block.
   * @param string $block_uuid
   *   The block UUID to find.
   *
   * @return string|null
   *   The location description, or NULL if not found.
   */
  public function getBlockLocation($parent_entity, string $block_uuid): ?string {
    if (!$parent_entity->hasField('layout_builder__layout')) {
      return NULL;
    }

    $layout_field = $parent_entity->get('layout_builder__layout');
    if ($layout_field->isEmpty()) {
      return NULL;
    }

    // Iterate through sections to find the block.
    foreach ($layout_field as $delta => $item) {
      $section = $item->section;
      if (!$section) {
        continue;
      }

      // Check each component in the section.
      foreach ($section->getComponents() as $component) {
        $configuration = $component->getConfiguration();

        // Check if this component references our block.
        if (isset($configuration['block_uuid']) && $configuration['block_uuid'] === $block_uuid) {
          // Try to get a label or region name.
          $region = $component->getRegion();
          $label = $configuration['label'] ?? NULL;

          if ($label) {
            return $label;
          }
          if ($region) {
            return ucfirst(str_replace('_', ' ', $region));
          }

          return "Section " . ($delta + 1);
        }
      }
    }

    return NULL;
  }

}
