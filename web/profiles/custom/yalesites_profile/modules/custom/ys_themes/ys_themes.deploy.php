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
