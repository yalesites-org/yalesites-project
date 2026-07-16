<?php

/**
 * @file
 * Post update functions for ys_core module.
 */

use Drupal\taxonomy\Entity\Term;

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

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds Academic Years vocabulary with year-range terms if they don't exist.
 *
 * Runs after config import so the vocabulary is guaranteed to be present.
 * The update hook (ys_core_update_10010) fires before config import in
 * drush deploy and skips on fresh multidevs where the vocab doesn't exist yet.
 */
function ys_core_deploy_10005() {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $vocabulary_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary');

  if (!$vocabulary_storage->load('academic_years')) {
    return t('Academic Years vocabulary does not exist; skipping.');
  }

  $existing_terms = $term_storage->loadByProperties(['vid' => 'academic_years']);
  $existing_names = array_map(fn($term) => $term->getName(), $existing_terms);

  $weight = 0;
  $created = 0;
  for ($start_year = 2026; $start_year >= 2000; $start_year--) {
    $term_name = $start_year . '-' . ($start_year + 1);
    if (!in_array($term_name, $existing_names)) {
      Term::create([
        'vid' => 'academic_years',
        'name' => $term_name,
        'weight' => $weight,
      ])->save();
      $created++;
    }
    $weight++;
  }

  if ($created === 0) {
    return t('All Academic Years terms already exist; nothing to create.');
  }

  return t('Populated Academic Years vocabulary with @count terms.', ['@count' => $created]);
}

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds DCN Types vocabulary with default identifier type terms if they don't
 * exist.
 *
 * Runs after config import so the vocabulary is guaranteed to be present.
 * The update hook (ys_core_update_10012) fires before config import in
 * drush deploy and skips on fresh multidevs where the vocab doesn't exist yet.
 */
function ys_core_deploy_10006() {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

  if (!\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load('dcn_types')) {
    return t('DCN Types vocabulary does not exist; skipping.');
  }

  $existing_names = array_map(
    fn($term) => $term->getName(),
    $term_storage->loadByProperties(['vid' => 'dcn_types'])
  );

  $default_terms = ['DOI', 'ISBN', 'ISSN', 'Report Number'];
  $created = 0;

  foreach ($default_terms as $weight => $term_name) {
    if (!in_array($term_name, $existing_names)) {
      Term::create([
        'vid' => 'dcn_types',
        'name' => $term_name,
        'weight' => $weight,
      ])->save();
      $created++;
    }
  }

  if ($created === 0) {
    return t('All DCN Types terms already exist; nothing to create.');
  }

  return t('Populated DCN Types vocabulary with @count terms.', ['@count' => $created]);
}
