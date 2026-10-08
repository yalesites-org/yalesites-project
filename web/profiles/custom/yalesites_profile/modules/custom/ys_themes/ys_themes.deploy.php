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
 * Sets field_style_video_playback to 'loop' on existing banner blocks.
 *
 * Image Banner and Grand Hero background videos always looped before this
 * field existed. Drupal applies a field's default only when an entity is
 * created, so without this pass the required dial would sit empty on every
 * existing banner and stop editors from saving until they picked a value.
 *
 * Runs after config import so the field is guaranteed to exist; an update hook
 * would fire before the field config lands and quietly match nothing.
 *
 * Every revision is visited, not just the latest: Layout Builder renders the
 * block revision a node revision pins, so a page with an unsaved draft pins
 * one block revision on the draft and an older one on the published revision.
 * Batched via the Sandbox API, as in ys_core_deploy_10007(), because banner
 * counts scale with pages.
 */
function ys_themes_deploy_10303(&$sandbox) {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $bundles = ['grand_hero', 'image_banner'];

  if (!isset($sandbox['processed'])) {
    $sandbox['processed'] = 0;
    $sandbox['backfilled'] = 0;
    $sandbox['total'] = $block_storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('type', $bundles, 'IN')
      ->count()
      ->execute();

    if ($sandbox['total'] == 0) {
      $sandbox['#finished'] = 1;
      return t('No Image Banner or Grand Hero blocks found.');
    }
  }

  // Paged over every revision rather than filtered to the empty ones, so the
  // result set doesn't shrink underneath the offset as values are filled in.
  // An allRevisions() query returns revision_id => entity_id.
  $result = $block_storage->getQuery()
    ->accessCheck(FALSE)
    ->allRevisions()
    ->condition('type', $bundles, 'IN')
    ->sort('revision_id')
    ->range($sandbox['processed'], 50)
    ->execute();

  foreach (array_keys($result) as $revision_id) {
    $sandbox['processed']++;

    $block = $block_storage->loadRevision($revision_id);
    if (!$block || !$block->get('field_style_video_playback')->isEmpty()) {
      continue;
    }

    // The behavior these banners had before the field existed — a constant
    // rather than a read of the dial's current default, so a later change to
    // that default cannot rewrite what existing banners do.
    $block->set('field_style_video_playback', 'loop');
    // Saving a loaded revision updates it in place rather than creating a new
    // one, so the revision id a layout pins to stays valid.
    $block->save();
    $sandbox['backfilled']++;
  }

  // An empty page means the count shrank under us; stop rather than spin.
  $sandbox['#finished'] = (empty($result) || $sandbox['processed'] >= $sandbox['total'])
    ? 1
    : $sandbox['processed'] / $sandbox['total'];

  if ($sandbox['#finished'] < 1) {
    return NULL;
  }

  if ($sandbox['backfilled'] === 0) {
    return t('No banner blocks needed a video playback backfill.');
  }

  return t('Set video playback to loop on @count banner block revision(s).', ['@count' => $sandbox['backfilled']]);
}
