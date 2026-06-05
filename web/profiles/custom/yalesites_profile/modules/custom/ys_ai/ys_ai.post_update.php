<?php

/**
 * @file
 * Post update hooks for the ys_ai module.
 */

use Drupal\Core\Utility\UpdateException;

/**
 * Migrates legacy AI metatags to the native field_ai_* fields.
 *
 * The AI metadata previously stored in the ai_engine "ai_engine" metatag group
 * (ai_disable_indexing, ai_description, ai_tags) inside field_metatags is moved
 * onto the native node fields field_ai_exclude / field_ai_description /
 * field_ai_tags. The legacy values remain in field_metatags but become inert
 * (the metatag group is hidden by the metatag-firehose form alter in
 * ys_ai.module).
 *
 * Runs as a post update so it executes after configuration import (drush
 * deploy), once the new fields exist. If the fields are not yet present the
 * update throws so it is retried after configuration import.
 */
function ys_ai_post_update_migrate_ai_metadata(&$sandbox) {
  // Ensure the native fields exist before migrating; otherwise this update has
  // run before configuration import and must be retried afterwards.
  $field_storage_definitions = \Drupal::service('entity_field.manager')
    ->getFieldStorageDefinitions('node');
  foreach (['field_ai_exclude', 'field_ai_description', 'field_ai_tags'] as $required) {
    if (!isset($field_storage_definitions[$required])) {
      throw new UpdateException(sprintf(
        'The %s field does not exist yet. Import configuration (drush cim) before running this update.',
        $required
      ));
    }
  }

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');

  if (!isset($sandbox['ids'])) {
    // Collect the ids of all nodes that have a non-empty field_metatags value.
    $query = \Drupal::database()->select('node__field_metatags', 'm')
      ->distinct();
    $query->addField('m', 'entity_id');
    $query->isNotNull('m.field_metatags_value');
    $query->condition('m.field_metatags_value', '', '<>');
    $sandbox['ids'] = array_values($query->execute()->fetchCol());
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['migrated'] = 0;
  }

  if (empty($sandbox['ids'])) {
    $sandbox['#finished'] = 1;
    return 'No nodes with metatags found; nothing to migrate.';
  }

  $batch = array_splice($sandbox['ids'], 0, 50);
  foreach ($node_storage->loadMultiple($batch) as $node) {
    if (!$node->hasField('field_metatags')) {
      continue;
    }
    $raw = $node->get('field_metatags')->value;
    if ($raw === NULL || $raw === '') {
      continue;
    }
    // metatag_data_decode() handles both the legacy serialized and current JSON
    // storage formats and returns only the overridden tags.
    $tags = metatag_data_decode($raw);
    if (!is_array($tags)) {
      continue;
    }

    $changed = FALSE;

    if ($node->hasField('field_ai_exclude')) {
      $exclude = isset($tags['ai_disable_indexing']) && $tags['ai_disable_indexing'] === 'disabled';
      if ((bool) $node->get('field_ai_exclude')->value !== $exclude) {
        $node->set('field_ai_exclude', $exclude);
        $changed = TRUE;
      }
    }
    if ($node->hasField('field_ai_description') && !empty($tags['ai_description'])) {
      $node->set('field_ai_description', $tags['ai_description']);
      $changed = TRUE;
    }
    if ($node->hasField('field_ai_tags') && !empty($tags['ai_tags'])) {
      $node->set('field_ai_tags', $tags['ai_tags']);
      $changed = TRUE;
    }

    if ($changed) {
      // Update in place: this is a one-time backfill, not an editorial change.
      $node->setNewRevision(FALSE);
      $node->save();
      $sandbox['migrated']++;
    }
  }

  $sandbox['#finished'] = empty($sandbox['ids']) ? 1 : 1 - (count($sandbox['ids']) / $sandbox['total']);

  if ($sandbox['#finished'] >= 1) {
    return sprintf('Migrated AI metadata on %d node(s).', $sandbox['migrated']);
  }
}
