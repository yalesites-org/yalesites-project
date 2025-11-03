<?php

/**
 * @file
 * Deploy hooks for ys_file_management module.
 */

/**
 * Recreate entity usage statistics after module installation and configuration.
 */
function ys_file_management_deploy_recreate_entity_usage_statistics(array &$sandbox = NULL): ?string {
  // Ensure entity_usage module is enabled before proceeding.
  if (!\Drupal::moduleHandler()->moduleExists('entity_usage')) {
    \Drupal::logger('ys_file_management')->warning('Cannot recreate entity usage statistics: entity_usage module is not enabled.');
    return 'Skipped entity usage statistics recreation: entity_usage module is not enabled.';
  }

  $entity_usage = \Drupal::service('entity_usage.usage');
  $entity_update_manager = \Drupal::service('entity_usage.entity_update_manager');
  $entity_type_manager = \Drupal::entityTypeManager();
  $config = \Drupal::config('entity_usage.settings');

  // Initialize sandbox on first run.
  if (!isset($sandbox['current_entity_type'])) {
    // Truncate the entity_usage table.
    if (method_exists($entity_usage, 'truncateTable')) {
      $entity_usage->truncateTable();
    }

    // Get list of entity types to track.
    $to_track = $config->get('track_enabled_source_entity_types');
    $entity_types = [];
    foreach ($entity_type_manager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!is_array($to_track) && $entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        if (!in_array($entity_type_id, ['file', 'user'])) {
          $entity_types[] = $entity_type_id;
        }
      }
      elseif (is_array($to_track) && in_array($entity_type_id, $to_track, TRUE)) {
        $entity_types[] = $entity_type_id;
      }
    }

    $sandbox['entity_types'] = $entity_types;
    $sandbox['current_entity_type_index'] = 0;
    $sandbox['current_entity_type'] = $entity_types[0] ?? NULL;
    $sandbox['current_id'] = 0;
    $sandbox['total_entity_types'] = count($entity_types);
    $sandbox['entities_processed'] = 0;
    $sandbox['#finished'] = 0;

    \Drupal::logger('ys_file_management')->info('Starting entity usage statistics recreation for @count entity types.', [
      '@count' => $sandbox['total_entity_types'],
    ]);
  }

  // Process current entity type.
  if ($sandbox['current_entity_type']) {
    $entity_type = $entity_type_manager->getDefinition($sandbox['current_entity_type']);
    $entity_storage = $entity_type_manager->getStorage($entity_type->id());
    $entity_type_key = $entity_type->getKey('id');

    // Load and process entities in batches of 50.
    $entity_ids = $entity_storage->getQuery()
      ->condition($entity_type_key, $sandbox['current_id'], '>')
      ->range(0, 50)
      ->accessCheck(FALSE)
      ->sort($entity_type_key)
      ->execute();

    if (!empty($entity_ids)) {
      foreach ($entity_storage->loadMultiple($entity_ids) as $entity) {
        try {
          $entity_update_manager->trackUpdateOnCreation($entity);
          $sandbox['current_id'] = $entity->id();
          $sandbox['entities_processed']++;
        }
        catch (\Exception $e) {
          \Drupal::logger('ys_file_management')->error('Error processing entity @type:@id - @message', [
            '@type' => $entity_type->id(),
            '@id' => $entity->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }
    else {
      // Move to next entity type.
      $sandbox['current_entity_type_index']++;
      $sandbox['current_id'] = 0;
      $sandbox['current_entity_type'] = $sandbox['entity_types'][$sandbox['current_entity_type_index']] ?? NULL;
    }
  }

  // Calculate progress.
  if ($sandbox['current_entity_type']) {
    $progress = $sandbox['current_entity_type_index'] / $sandbox['total_entity_types'];
    $sandbox['#finished'] = $progress;
  }
  else {
    $sandbox['#finished'] = 1;
    \Drupal::logger('ys_file_management')->info('Entity usage statistics recreation completed. Processed @count entities.', [
      '@count' => $sandbox['entities_processed'],
    ]);
    return t('Successfully recreated entity usage statistics for @count entities across @types entity types.', [
      '@count' => $sandbox['entities_processed'],
      '@types' => $sandbox['total_entity_types'],
    ]);
  }

  return NULL;
}
