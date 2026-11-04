<?php

/**
 * @file
 * Post update functions for ys_views_basic.
 */

use Drupal\ys_views_basic\Service\ListingMigrator;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Converts profile_directory listing blocks to small profile cards (#1682).
 *
 * This is a post-update rather than a deploy hook on purpose. drush deploy
 * runs updatedb, then config:import, then the deploy hooks, and the config
 * import deletes the profile_directory bundle and its field_view_params
 * instance. A deploy hook would run after that and could no longer read the
 * stored params; a post-update runs while the bundle still exists.
 *
 * Where deploy_10001/10002 have not run yet they target profile_card
 * directly, so no profile_directory block exists and this is a no-op. It
 * catches up environments that already ran those hooks. Idempotent: a second
 * run finds no profile_directory blocks. Batched like the deploy hooks: up
 * to ListingMigrator::BATCH_SIZE blocks per pass, then the layouts in chunks.
 */
function ys_views_basic_post_update_retire_profile_directory(&$sandbox) {
  /** @var \Drupal\ys_views_basic\Service\ListingMigrator $migrator */
  $migrator = \Drupal::service('ys_views_basic.listing_migrator');
  $logger = \Drupal::logger('ys_views_basic');
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  if (!isset($sandbox['ids'])) {
    $ids = $block_storage->getQuery()
      ->condition('type', 'profile_directory')
      ->accessCheck(FALSE)
      ->execute();
    $logger->notice('Profile directory retirement pre-flight: @count profile_directory blocks to convert.', ['@count' => count($ids)]);
    $sandbox['ids'] = array_values($ids);
    $sandbox['total'] = count($ids);
    $sandbox['blocks_done'] = FALSE;
    $sandbox['converted'] = 0;
    $sandbox['placements'] = [];
  }

  if (!$sandbox['blocks_done']) {
    $chunk = array_splice($sandbox['ids'], 0, ListingMigrator::BATCH_SIZE);
    foreach ($block_storage->loadMultiple($chunk) as $block) {
      $stored = $block->get('field_view_params')->isEmpty()
        ? NULL
        : json_decode($block->get('field_view_params')->first()->getValue()['params'] ?? '', TRUE);
      // A block saved without params gets the directory preset, so the card
      // listing still has every key setupView() reads.
      $params = is_array($stored) ? $stored : ViewsBasicManager::predecessorPreset('directory')['params'];

      // Both bundles carry field_view_params, so one save swaps the bundle
      // and writes the converted params.
      $block->set('type', 'profile_card');
      $block->set('field_view_params', ['params' => json_encode(ViewsBasicManager::directoryToCardParams($params))]);
      $block->save();
      // A bundle swap leaves prior field-table rows stamped with the old
      // bundle.
      $migrator->patchBlockFieldBundles($block->id(), 'profile_card');
      $sandbox['converted']++;
    }
    if ($chunk) {
      $block_storage->resetCache($chunk);
    }

    if ($sandbox['ids']) {
      $sandbox['#finished'] = 0.5 * ($sandbox['total'] - count($sandbox['ids'])) / $sandbox['total'];
      return NULL;
    }
    $sandbox['blocks_done'] = TRUE;
  }

  if (!$migrator->rewritePlacements(['profile_directory'], $sandbox['placements'])) {
    $sandbox['#finished'] = 0.5;
    return NULL;
  }
  $converted = $sandbox['converted'];
  $rewritten = $sandbox['placements']['rewritten'];

  $remaining_blocks = (int) $block_storage->getQuery()
    ->condition('type', 'profile_directory')
    ->accessCheck(FALSE)
    ->count()
    ->execute();
  $remaining_refs = $migrator->countPlacements('inline_block:profile_directory"');
  $logger->notice('Profile directory retirement complete: @c blocks converted, @r placements rewritten. Remaining profile_directory blocks: @rb; remaining inline_block:profile_directory references: @rr.', [
    '@c' => $converted,
    '@r' => $rewritten,
    '@rb' => $remaining_blocks,
    '@rr' => $remaining_refs,
  ]);

  \Drupal::service('cache.render')->invalidateAll();
  $migrator->clearLayoutTempstore($sandbox['placements']['entities']);
  $sandbox['#finished'] = 1;

  return t('Profile directory retirement: converted @c blocks; rewrote @r layout placements; @rb profile_directory blocks and @rr inline_block:profile_directory references remain.', [
    '@c' => $converted,
    '@r' => $rewritten,
    '@rb' => $remaining_blocks,
    '@rr' => $remaining_refs,
  ]);
}
