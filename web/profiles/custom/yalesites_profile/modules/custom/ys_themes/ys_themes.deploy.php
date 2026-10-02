<?php

/**
 * @file
 * Drush deploy hooks for ys_themes module.
 */

/**
 * Updates existing accordions with default value for component theme.
 */
function ys_themes_deploy_10301() {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $query = $block_storage->getQuery();
  $query->accessCheck(FALSE)
    ->condition('type', 'accordion');

  $block_ids = $query->execute();

  foreach ($block_ids as $id) {
    $block = $block_storage->load($id);
    /** @var Drupal\Core\Entity\Sql\SqlContentEntityStorage $block_storage */
    $latestRevisionId = $block_storage->getLatestRevisionId($id);

    if (!$latestRevisionId) {
      $latestRevision = $block_storage->createRevision($block);
    }
    else {
      $latestRevision = $block_storage->loadRevision($latestRevisionId);
    }

    /** @var Drupal\block_content\Entity\BlockContent $latestRevision */
    if ($latestRevision->get('field_style_color')->isEmpty()) {
      $latestRevision->set('field_style_color', 'default');
      $latestRevision->save();
    }
  }
}

/**
 * Sets field_style_width to 'site' on existing banner blocks.
 */
function ys_themes_deploy_10302() {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $bundles = ['cta_banner', 'grand_hero', 'image_banner'];

  foreach ($bundles as $bundle) {
    $ids = $block_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->execute();

    foreach ($ids as $id) {
      $block = $block_storage->load($id);
      /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $block_storage */
      $latest_revision_id = $block_storage->getLatestRevisionId($id);

      if (!$latest_revision_id) {
        $latest_revision = $block_storage->createRevision($block);
      }
      else {
        $latest_revision = $block_storage->loadRevision($latest_revision_id);
      }

      /** @var \Drupal\block_content\Entity\BlockContent $latest_revision */
      if ($latest_revision->get('field_style_width')->isEmpty()) {
        $latest_revision->set('field_style_width', 'site');
        $latest_revision->save();
      }
    }
  }
}

/**
 * Backfills the wave 1 dials on existing divider, callout, cta_banner blocks.
 *
 * The fields are required, so an existing block with no value cannot be
 * re-saved until an editor picks one. These defaults render the same as NULL.
 */
function ys_themes_deploy_10303(&$sandbox) {
  $defaults = [
    'divider' => ['field_style_thickness', '1'],
    'callout' => ['field_style_variation', 'cta'],
    'cta_banner' => ['field_button_style_consistency', 'filled_outline'],
  ];
  /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $block_storage */
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = array_values($block_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', array_keys($defaults), 'IN')
      ->execute());
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['updated'] = 0;
  }

  foreach (array_splice($sandbox['ids'], 0, 50) as $id) {
    $latest_revision_id = $block_storage->getLatestRevisionId($id);
    $block = $latest_revision_id
      ? $block_storage->loadRevision($latest_revision_id)
      : $block_storage->createRevision($block_storage->load($id));

    /** @var \Drupal\block_content\Entity\BlockContent $block */
    [$field, $value] = $defaults[$block->bundle()];
    if ($block->hasField($field) && $block->get($field)->isEmpty()) {
      $block->set($field, $value);
      $block->save();
      $sandbox['updated']++;
    }
  }

  $sandbox['#finished'] = $sandbox['total']
    ? 1 - count($sandbox['ids']) / $sandbox['total']
    : 1;

  return t('Set default dial values on @count block(s).', ['@count' => $sandbox['updated']]);
}
