<?php

/**
 * @file
 * Post update functions for ys_file_management module.
 */

/**
 * Recreate entity usage statistics after module installation and configuration.
 */
function ys_file_management_post_update_recreate_entity_usage_statistics() {
  // Ensure entity_usage module is enabled before attempting to recreate stats.
  if (!\Drupal::moduleHandler()->moduleExists('entity_usage')) {
    \Drupal::logger('ys_file_management')->warning('Cannot recreate entity usage statistics: entity_usage module is not enabled.');
    return;
  }

  try {
    // Get the entity usage batch manager service.
    $batch_manager = \Drupal::service('entity_usage.batch_manager');

    // Recreate all entity usage statistics.
    $batch_manager->recreate();

    \Drupal::logger('ys_file_management')->info('Entity usage statistics recreation batch has been initiated.');
  }
  catch (\Exception $e) {
    \Drupal::logger('ys_file_management')->error('Failed to initiate entity usage statistics recreation: @message', [
      '@message' => $e->getMessage(),
    ]);
  }
}
