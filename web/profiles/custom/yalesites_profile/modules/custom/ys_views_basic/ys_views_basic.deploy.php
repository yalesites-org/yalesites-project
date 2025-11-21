<?php

/**
 * @file
 * Contains ys_views_basic.deploy.php.
 */

/**
 * Converts view blocks with calendar view_mode to event_calendar blocks.
 */
function ys_views_basic_deploy_10000() {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $query = $storage->getQuery()
    ->condition('type', 'view')
    ->accessCheck(TRUE);
  $ids = $query->execute();
  $blocks = $storage->loadMultiple($ids);

  foreach ($blocks as $block) {
    $changed = FALSE;
    foreach ($block->get('field_view_params') as $item) {
      $params = $item->getValue();
      $params_decoded = json_decode($params['params'] ?? '', TRUE);
      if (!empty($params_decoded['view_mode']) && $params_decoded['view_mode'] === 'calendar') {
        // Change type first and save.
        $block->set('type', 'event_calendar');
        // Set the description/label.
        $block->set('info', 'Event Calendar');
        $block->save();
        // Reload the block so the new fields are available.
        $block = \Drupal::entityTypeManager()->getStorage('block_content')->load($block->id());
        // Copy the params value from field_view_params to field_basic_params.
        if (isset($params['params'])) {
          $block->set('field_basic_params', [['params' => $params['params']]]);
          $changed = TRUE;
        }
      }
    }
    if ($changed) {
      $block->save();
    }
  }

  // After converting blocks, update Layout Builder layouts.
  $entity_type_manager = \Drupal::entityTypeManager();
  $entity_field_manager = \Drupal::service('entity_field.manager');
  $field_map = $entity_field_manager->getFieldMap();

  foreach ($field_map as $entity_type_id => $fields) {
    if (!isset($fields['layout_builder__layout'])) {
      continue;
    }
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $query = $storage->getQuery()->exists('layout_builder__layout');
    $query->accessCheck(TRUE);
    $entity_ids = $query->execute();
    if (empty($entity_ids)) {
      continue;
    }
    $entities = $storage->loadMultiple($entity_ids);
    foreach ($entities as $entity) {
      $changed = FALSE;
      $sections = $entity->get('layout_builder__layout')->getValue();
      foreach ($sections as &$section) {
        $section_obj = $section['section'];
        if (is_string($section_obj)) {
          $section_obj = unserialize($section_obj, ['allowed_classes' => ['Drupal\\layout_builder\\Section']]);
        }
        $components = $section_obj->getComponents();
        foreach ($components as $component) {
          $config = $component->get('configuration');
          if (!empty($config['block_revision_id'])) {
            $block = \Drupal::entityTypeManager()->getStorage('block_content')->loadRevision($config['block_revision_id']);
            if ($block && $block->bundle() === 'event_calendar') {
              $config['id'] = 'inline_block:event_calendar';
              $config['label'] = 'Event Calendar';
              $component->set('configuration', $config);
              $changed = TRUE;
            }
          }
        }
        $section['section'] = $section_obj;
      }
      if ($changed) {
        $entity->set('layout_builder__layout', $sections);
        $entity->save();
      }
    }
  }

  // Clear render cache.
  \Drupal::service('cache.render')->invalidateAll();
  // Clear node entity cache (if nodes were updated).
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  // Clear Layout Builder tempstore overrides.
  if (\Drupal::database()->schema()->tableExists('key_value_expire')) {
    \Drupal::database()->delete('key_value_expire')
      ->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides')
      ->execute();
  }
}
