<?php

/**
 * @file
 * Post update functions for ys_core module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Sets the default taxonomy of custom_vocab if it is NULL.
 */
function ys_core_deploy_10001() {
  $vocab = \Drupal::configFactory()->getEditable('taxonomy.vocabulary.custom_vocab');
  if ($vocab && $vocab->get('name') === NULL) {
    $vocab->set('name', 'Custom Vocab')->save();

    $content_types = ['event', 'page', 'post', 'profile'];

    foreach ($content_types as $content_type) {
      \Drupal::configFactory()->getEditable("field.field.node.{$content_type}.field_custom_vocab")
        ->set('label', 'Custom Vocab')
        ->save();
    }

    \Drupal::cache('discovery')->invalidateAll();
  }
}

/**
 * Implements hook_update().
 *
 * Converts field_style_variation settings to field_focus.
 */
function ys_core_deploy_10002() {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $query = $block_storage->getQuery();
  $query->accessCheck(FALSE)
    ->condition('type', 'content_spotlight');

  $ids = $query->execute();

  foreach ($ids as $id) {
    $block = $block_storage->load($id);
    $latestRevisionId = $block_storage->getLatestRevisionId($id);

    if (!$latestRevisionId) {
      $latestRevision = $block_storage->createRevision($block);
    }
    else {
      $latestRevision = $block_storage->loadRevision($latestRevisionId);
    }

    $field_style_variation = $latestRevision->get('field_style_variation');

    if ($field_style_variation) {
      $latestRevision->set('field_focus', $field_style_variation->value);
      $latestRevision->save();
    }
  }

}
