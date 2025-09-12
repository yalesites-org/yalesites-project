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

    $content_types = ['event', 'page', 'post', 'profile', 'resource'];

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

/**
 * Implements hook_update().
 *
 * Transforms the values currently in field_hide_sharing_links
 * to field_show_social_media_sharing for a post node.
 *
 * Remember to later remove the field_hide_sharing_links field.
 */
function ys_core_deploy_10003() {
  $ids = \Drupal::entityQuery('node')
    ->condition('type', 'post')
    ->accessCheck(FALSE)
    ->execute();

  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids);

  foreach ($nodes as $node) {
    if (!$node->hasField('field_hide_sharing_links')) {
      continue;
    }

    $node->set(
      'field_show_social_media_sharing',
      $node->get('field_hide_sharing_links')->value == '0' ? '1' : '0'
    );

    $node->save();
  }
}

/**
 * Implements hook_deploy_NAME().
 *
 * Updates the field_custom_vocab label for resources content type
 * to match the current custom_vocab_name setting.
 */
function ys_core_deploy_10004() {
  // Get the current custom vocabulary name from site settings.
  $custom_vocab_name = \Drupal::config('ys_core.site')->get('taxonomy.custom_vocab_name') ?? 'Custom Vocab';

  // Update the field label for the resource content type.
  $field_config = \Drupal::configFactory()->getEditable('field.field.node.resource.field_custom_vocab');

  if (!$field_config->isNew()) {
    $field_config->set('label', $custom_vocab_name)->save();

    // Clear cache so the new label is reflected.
    \Drupal::service('cache.discovery')->invalidateAll();

    \Drupal::messenger()->addStatus(t('Updated field_custom_vocab label for resources to "@label".', ['@label' => $custom_vocab_name]));
  }
}
