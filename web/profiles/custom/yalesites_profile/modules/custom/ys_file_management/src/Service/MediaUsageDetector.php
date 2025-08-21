<?php

namespace Drupal\ys_file_management\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\media\MediaInterface;

/**
 * Service for detecting media entity usage across the site.
 */
class MediaUsageDetector {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a MediaUsageDetector object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    LoggerChannelInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
  }

  /**
   * Checks if a media entity is referenced elsewhere.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to check.
   *
   * @return bool
   *   TRUE if the media is used elsewhere, FALSE otherwise.
   */
  public function isMediaUsedElsewhere(MediaInterface $media): bool {
    $media_id = $media->id();

    // Check common entity types that might reference media.
    $entity_types_to_check = $this->getEntityTypesToCheck();

    foreach ($entity_types_to_check as $entity_type_id) {
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        continue;
      }

      try {
        if ($this->checkEntityTypeForMediaUsage($entity_type_id, $media_id)) {
          return TRUE;
        }
      }
      catch (\Exception $e) {
        // Log errors but continue checking other entity types.
        $this->logger->error('Error checking @entity_type for media usage: @error', [
          '@entity_type' => $entity_type_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->info('No media usage found for media @mid', ['@mid' => $media_id]);
    return FALSE;
  }

  /**
   * Gets detailed usage information for a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to check.
   *
   * @return array
   *   Array of usage details with entity type, bundle, field, and count.
   */
  public function getUsageDetails(MediaInterface $media): array {
    $media_id = $media->id();
    $usage_details = [];

    $entity_types_to_check = $this->getEntityTypesToCheck();

    foreach ($entity_types_to_check as $entity_type_id) {
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        continue;
      }

      try {
        $details = $this->getEntityTypeUsageDetails($entity_type_id, $media_id);
        $usage_details = array_merge($usage_details, $details);
      }
      catch (\Exception $e) {
        $this->logger->error('Error getting usage details for @entity_type: @error', [
          '@entity_type' => $entity_type_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $usage_details;
  }

  /**
   * Gets the total usage count for a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity to check.
   *
   * @return int
   *   The total number of places this media is used.
   */
  public function getUsageCount(MediaInterface $media): int {
    $usage_details = $this->getUsageDetails($media);
    return array_sum(array_column($usage_details, 'count'));
  }

  /**
   * Gets the list of entity types to check for media usage.
   *
   * @return array
   *   Array of entity type IDs to check.
   */
  protected function getEntityTypesToCheck(): array {
    // Default entity types - could be made configurable in the future.
    return ['node', 'block_content', 'paragraph'];
  }

  /**
   * Checks a specific entity type for media usage.
   *
   * @param string $entity_type_id
   *   The entity type ID to check.
   * @param int $media_id
   *   The media entity ID to look for.
   *
   * @return bool
   *   TRUE if media is found in this entity type, FALSE otherwise.
   */
  protected function checkEntityTypeForMediaUsage(string $entity_type_id, int $media_id): bool {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);

    foreach (array_keys($bundle_info) as $bundle) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        if ($this->isMediaReferenceField($field_definition)) {
          $query = $storage->getQuery();
          $query->condition($field_name . '.target_id', $media_id);
          $query->accessCheck(FALSE);
          $query->range(0, 1);
          $entities = $query->execute();

          if (!empty($entities)) {
            // Log what found the usage.
            $this->logger->info('Media @mid found in @entity_type.@bundle.@field', [
              '@mid' => $media_id,
              '@entity_type' => $entity_type_id,
              '@bundle' => $bundle,
              '@field' => $field_name,
            ]);
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Gets detailed usage information for a specific entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID to check.
   * @param int $media_id
   *   The media entity ID to look for.
   *
   * @return array
   *   Array of usage details for this entity type.
   */
  protected function getEntityTypeUsageDetails(string $entity_type_id, int $media_id): array {
    $details = [];
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);

    foreach (array_keys($bundle_info) as $bundle) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        if ($this->isMediaReferenceField($field_definition)) {
          $query = $storage->getQuery();
          $query->condition($field_name . '.target_id', $media_id);
          $query->accessCheck(FALSE);
          $entities = $query->execute();

          if (!empty($entities)) {
            $details[] = [
              'entity_type' => $entity_type_id,
              'bundle' => $bundle,
              'field' => $field_name,
              'count' => count($entities),
              'entity_ids' => array_values($entities),
            ];
          }
        }
      }
    }

    return $details;
  }

  /**
   * Checks if a field definition is a media reference field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition to check.
   *
   * @return bool
   *   TRUE if this is a media reference field, FALSE otherwise.
   */
  protected function isMediaReferenceField($field_definition): bool {
    return $field_definition->getType() === 'entity_reference' &&
           $field_definition->getSetting('target_type') === 'media';
  }

}
