<?php

/**
 * @file
 * Drush deploy hooks for ys_localist module.
 */

use Drupal\Component\Utility\Html;
use Drupal\node\NodeInterface;

/**
 * Implements hook_deploy_NAME().
 *
 * Copies place and room into the native location fields on hand-authored
 * events.
 *
 * field_event_place and field_event_room are now locked as Localist-owned in
 * _ys_core_disable_event_fields(), and the event page only renders them for
 * Localist events. A hand-authored event (no field_localist_id) that used them
 * would silently lose its location, and editors could no longer fix it. This
 * copies the place term's address into field_event_address and writes the
 * place name and room into field_address_additional_info, filling only fields
 * that are still empty, so it is idempotent and never overwrites an editor.
 *
 * Runs after config:import so the native fields exist. Both the default and
 * the latest revision are filled, so a pending draft keeps the location when
 * it is published. Saves are marked syncing so 'changed' is not bumped.
 */
function ys_localist_deploy_10001(array &$sandbox) {
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');

  if (!isset($sandbox['ids'])) {
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'event');
    if (!isset($fields['field_event_address'], $fields['field_address_additional_info'])) {
      $sandbox['#finished'] = 1;
      return t('Events have no native location fields; skipping.');
    }

    $legacy = array_intersect(['field_event_place', 'field_event_room'], array_keys($fields));
    if (!$legacy) {
      $sandbox['#finished'] = 1;
      return t('Events have no place or room fields; skipping.');
    }

    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event');
    $has_legacy = $query->orConditionGroup();
    foreach ($legacy as $field_name) {
      $has_legacy->exists($field_name);
    }
    $query->condition($has_legacy);
    if (isset($fields['field_localist_id'])) {
      $query->notExists('field_localist_id');
    }

    $sandbox['ids'] = array_values($query->execute());
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['backfilled'] = 0;
  }

  foreach (array_splice($sandbox['ids'], 0, 50) as $nid) {
    $default = $node_storage->load($nid);
    if (!$default) {
      continue;
    }
    $revisions = [$default];
    $latest_id = $node_storage->getLatestRevisionId($nid);
    if ($latest_id && $latest_id != $default->getRevisionId()) {
      $revisions[] = $node_storage->loadRevision($latest_id);
    }
    $changed = FALSE;
    foreach (array_filter($revisions) as $node) {
      if (_ys_localist_backfill_event_location($node)) {
        // Saving a loaded revision updates it in place.
        $node->setSyncing(TRUE);
        $node->save();
        $changed = TRUE;
      }
    }
    if ($changed) {
      $sandbox['backfilled']++;
    }
  }

  if ($sandbox['ids']) {
    $sandbox['#finished'] = 1 - (count($sandbox['ids']) / $sandbox['total']);
    return NULL;
  }
  $sandbox['#finished'] = 1;

  if ($sandbox['backfilled'] === 0) {
    return t('No hand-authored events needed a location backfill.');
  }
  return t('Copied place and room into the native location fields on @count event(s).', [
    '@count' => $sandbox['backfilled'],
  ]);
}

/**
 * Fills a hand-authored event's empty native location fields.
 *
 * @return bool
 *   TRUE if the node was changed and needs saving.
 */
function _ys_localist_backfill_event_location(NodeInterface $node): bool {
  if ($node->hasField('field_localist_id') && !$node->get('field_localist_id')->isEmpty()) {
    return FALSE;
  }

  $place = $node->hasField('field_event_place') ? $node->get('field_event_place')->entity : NULL;
  $room = $node->hasField('field_event_room') ? trim((string) $node->get('field_event_room')->value) : '';
  $changed = FALSE;

  if ($node->get('field_event_address')->isEmpty()
    && $place && $place->hasField('field_address') && !$place->get('field_address')->isEmpty()) {
    $node->set('field_event_address', $place->get('field_address')->first()->getValue());
    $changed = TRUE;
  }

  $parts = array_filter([$place ? trim($place->label()) : '', $room], 'strlen');
  if ($parts && $node->get('field_address_additional_info')->isEmpty()) {
    $node->set('field_address_additional_info', [
      'value' => '<p>' . Html::escape(implode(', ', $parts)) . '</p>',
      'format' => 'basic_html',
    ]);
    $changed = TRUE;
  }

  return $changed;
}
