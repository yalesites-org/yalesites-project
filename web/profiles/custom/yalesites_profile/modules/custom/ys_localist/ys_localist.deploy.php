<?php

/**
 * @file
 * Deploy hooks for the YS Localist module.
 */

use Drupal\Component\Utility\Html;
use Drupal\node\NodeInterface;

/**
 * Moves Room into Location details on events Localist does not manage.
 *
 * Room is now read-only and only shown on Localist events, so editors who
 * typed addresses into it would otherwise lose them (issue #750). Pending
 * drafts are moved too, or publishing one would bring the old Room back.
 */
function ys_localist_deploy_10001(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  // Entity IDs with Room set on the default or the latest revision.
  $pending = function (?int $limit = NULL) use ($storage): array {
    $ids = [];
    foreach ([FALSE, TRUE] as $latest) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->exists('field_event_room')
        ->notExists('field_localist_id')
        ->range(NULL, $limit);
      if ($latest) {
        $query->latestRevision();
      }
      $ids = array_merge($ids, array_values($query->execute()));
    }
    return array_unique($ids);
  };

  if (!isset($sandbox['total'])) {
    $sandbox['total'] = count($pending());
    $sandbox['done'] = 0;
  }

  // Each save clears Room, so the queries shrink until they are empty.
  $ids = $pending(50);
  foreach ($storage->loadMultiple($ids) as $node) {
    _ys_localist_move_room_to_location_details($node);
    $latestId = $storage->getLatestRevisionId($node->id());
    if ($latestId != $node->getRevisionId()) {
      _ys_localist_move_room_to_location_details($storage->loadRevision($latestId));
    }
    $sandbox['done']++;
  }

  $sandbox['#finished'] = (!$ids || $sandbox['done'] >= $sandbox['total']) ? 1 : $sandbox['done'] / $sandbox['total'];
  return (string) t('Moved Room into Location details on @count events.', ['@count' => $sandbox['done']]);
}

/**
 * Moves one event revision's Room into Location details, in place.
 */
function _ys_localist_move_room_to_location_details(NodeInterface $node): void {
  if ($node->get('field_event_room')->isEmpty()) {
    return;
  }
  $room = trim((string) $node->get('field_event_room')->value);
  if ($room !== '') {
    $details = $node->get('field_event_location_details');
    $node->set('field_event_location_details', [
      'value' => ($details->value ?? '') . '<p>' . Html::escape($room) . '</p>',
      'format' => $details->format ?? 'restricted_html',
    ]);
  }
  $node->set('field_event_room', NULL);
  $node->setNewRevision(FALSE);
  $node->setSyncing(TRUE);
  $node->save();
}
