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
