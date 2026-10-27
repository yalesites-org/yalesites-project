<?php

/**
 * @file
 * Contains ys_views_basic.deploy.php.
 */

use Drupal\ys_views_basic\Service\ListingMigrator;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Converts view blocks with calendar view_mode to event_calendar blocks.
 */
function ys_views_basic_deploy_10000() {
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $query = $storage->getQuery()
    ->condition('type', 'view')
    ->accessCheck(TRUE);
  $ids = $query->execute();
  $blocks = $storage->loadMultiple($ids);

  foreach ($blocks as $block) {
    $changed = FALSE;
    foreach ($block->get('field_view_params') as $item) {
      $params = $item->getValue();
      $params_decoded = json_decode($params['params'] ?? '', TRUE);
      if (!empty($params_decoded['view_mode']) && $params_decoded['view_mode'] === 'calendar') {
        // Change type first and save.
        $block->set('type', 'event_calendar');
        // Set the description/label.
        $block->set('info', 'Event Calendar');
        $block->save();
        // Reload the block so the new fields are available.
        $block = \Drupal::entityTypeManager()->getStorage('block_content')->load($block->id());
        // Copy the params value from field_view_params to field_basic_params.
        if (isset($params['params'])) {
          $block->set('field_basic_params', [['params' => $params['params']]]);
          $changed = TRUE;
        }
      }
    }
    if ($changed) {
      $block->save();
    }
  }

  // After converting blocks, update Layout Builder layouts.
  $entity_type_manager = \Drupal::entityTypeManager();
  $entity_field_manager = \Drupal::service('entity_field.manager');
  $field_map = $entity_field_manager->getFieldMap();

  foreach ($field_map as $entity_type_id => $fields) {
    if (!isset($fields['layout_builder__layout'])) {
      continue;
    }
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $query = $storage->getQuery()->exists('layout_builder__layout');
    $query->accessCheck(TRUE);
    $entity_ids = $query->execute();
    if (empty($entity_ids)) {
      continue;
    }
    $entities = $storage->loadMultiple($entity_ids);
    foreach ($entities as $entity) {
      $changed = FALSE;
      $sections = $entity->get('layout_builder__layout')->getValue();
      foreach ($sections as &$section) {
        $section_obj = $section['section'];
        if (is_string($section_obj)) {
          $section_obj = unserialize($section_obj, ['allowed_classes' => ['Drupal\\layout_builder\\Section']]);
        }
        $components = $section_obj->getComponents();
        foreach ($components as $component) {
          $config = $component->get('configuration');
          if (!empty($config['block_revision_id'])) {
            $block = \Drupal::entityTypeManager()->getStorage('block_content')->loadRevision($config['block_revision_id']);
            if ($block && $block->bundle() === 'event_calendar') {
              $config['id'] = 'inline_block:event_calendar';
              $config['label'] = 'Event Calendar';
              $component->set('configuration', $config);
              $changed = TRUE;
            }
          }
        }
        $section['section'] = $section_obj;
      }
      if ($changed) {
        $entity->set('layout_builder__layout', $sections);
        $entity->save();
      }
    }
  }

  // Clear render cache.
  \Drupal::service('cache.render')->invalidateAll();
  // Clear node entity cache (if nodes were updated).
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  \Drupal::service('ys_views_basic.listing_migrator')->clearAllLayoutTempstore();
}

/**
 * Migrates legacy "view" blocks to per-(content type, display mode) bundles.
 *
 * In-place bundle swap keyed on the stored content type and view mode together
 * (ADR DR-9, ticket #1169): e.g. {post, card} -> post_card. Because every new
 * bundle shares the existing field_view_params storage (DR-3) this copies no
 * data, except that {profile, directory} lands on profile_card with its params
 * rewritten to small cards (#1682). The hook is idempotent (blocks already
 * in a target bundle are skipped), pre-flight counts and logs the work,
 * rewrites inline_block:view plugin IDs across ALL revisions, and verifies
 * zero remaining "view" blocks/references.
 *
 * Batched: each pass re-bundles up to ListingMigrator::BATCH_SIZE blocks, then
 * walks the layouts in chunks; caches are cleared once, in the final pass.
 */
function ys_views_basic_deploy_10001(&$sandbox) {
  /** @var \Drupal\ys_views_basic\Service\ListingMigrator $migrator */
  $migrator = \Drupal::service('ys_views_basic.listing_migrator');
  $logger = \Drupal::logger('ys_views_basic');
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  // --- Pre-flight ---------------------------------------------------------.
  if (!isset($sandbox['ids'])) {
    $view_ids = $block_storage->getQuery()
      ->condition('type', 'view')
      ->accessCheck(FALSE)
      ->execute();
    $logger->notice('View migration pre-flight: @count "view" blocks to evaluate.', ['@count' => count($view_ids)]);

    // Inline-only placements are expected (DR-9/DR-12). Warn if any reusable
    // (block_content:) placement of a listing exists, since this migration
    // does not rewrite those.
    $reusable = $migrator->countPlacements('block_content:');
    if ($reusable > 0) {
      $logger->warning('View migration: found @n layout rows containing reusable (block_content:) placements; verify these on staging.', ['@n' => $reusable]);
    }

    $sandbox['ids'] = array_values($view_ids);
    $sandbox['total'] = count($view_ids);
    $sandbox['blocks_done'] = FALSE;
    $sandbox['distribution'] = [];
    $sandbox['migrated'] = 0;
    $sandbox['placements'] = [];
  }

  // --- In-place bundle swap, one chunk per pass ---------------------------.
  if (!$sandbox['blocks_done']) {
    $chunk = array_splice($sandbox['ids'], 0, ListingMigrator::BATCH_SIZE);
    foreach ($block_storage->loadMultiple($chunk) as $block) {
      $params = $block->get('field_view_params')->isEmpty()
        ? NULL
        : ($block->get('field_view_params')->first()->getValue()['params'] ?? NULL);
      $decoded = $params ? json_decode($params, TRUE) : NULL;
      $type = $decoded['filters']['types'][0] ?? NULL;
      $view_mode = $decoded['view_mode'] ?? NULL;
      $target = ViewsBasicManager::migrationTargetBundle($type, $view_mode);

      if ($target === NULL) {
        $logger->warning('View migration: skipping block @id with unmappable (type=@t, view_mode=@v).', [
          '@id' => $block->id(),
          '@t' => $type ?? 'NULL',
          '@v' => $view_mode ?? 'NULL',
        ]);
        continue;
      }

      $block->set('type', $target);
      if ($view_mode === 'directory') {
        $block->set('field_view_params', ['params' => json_encode(ViewsBasicManager::directoryToCardParams($decoded))]);
      }
      $block->save();

      // A bundle swap + save leaves prior field-table rows stamped "view";
      // patch the bundle column on every field's data and revision tables.
      // All revisions of a migrated block belong to the new bundle: the
      // target is derived from the block's current params, one target per
      // block (ADR DR-9).
      $migrator->patchBlockFieldBundles($block->id(), $target);

      $sandbox['distribution']["$type/$view_mode"] = ($sandbox['distribution']["$type/$view_mode"] ?? 0) + 1;
      $sandbox['migrated']++;
    }
    if ($chunk) {
      $block_storage->resetCache($chunk);
    }

    if ($sandbox['ids']) {
      $sandbox['#finished'] = 0.5 * ($sandbox['total'] - count($sandbox['ids'])) / $sandbox['total'];
      return NULL;
    }
    $sandbox['blocks_done'] = TRUE;
    $logger->notice('View migration: swapped @n blocks. Distribution: @dist', [
      '@n' => $sandbox['migrated'],
      '@dist' => json_encode($sandbox['distribution']),
    ]);
  }

  // --- Rewrite inline_block:view placements across ALL revisions ----------.
  // The rewrite reads each referenced block's CURRENT bundle rather than a
  // per-run map, so it is idempotent: re-running after an interrupted deploy
  // still rewrites any placement whose block has already been re-bundled.
  if (!$migrator->rewritePlacements(['view'], $sandbox['placements'])) {
    $sandbox['#finished'] = 0.5;
    return NULL;
  }
  $migrated = $sandbox['migrated'];
  $rewritten = $sandbox['placements']['rewritten'];

  // --- Post-run verification ---------------------------------------------.
  $remaining_views = (int) $block_storage->getQuery()
    ->condition('type', 'view')
    ->accessCheck(FALSE)
    ->count()
    ->execute();
  $remaining_refs = $migrator->countPlacements('inline_block:view"');
  $logger->notice('View migration complete: @m blocks migrated, @r placements rewritten. Remaining "view" blocks: @rv; remaining inline_block:view references: @rr.', [
    '@m' => $migrated,
    '@r' => $rewritten,
    '@rv' => $remaining_views,
    '@rr' => $remaining_refs,
  ]);

  // Clear caches so the rewritten layouts render with the new bundles, and
  // discard the drafts of the pages whose layouts were rewritten.
  \Drupal::service('cache.render')->invalidateAll();
  $migrator->clearLayoutTempstore($sandbox['placements']['entities']);
  $sandbox['#finished'] = 1;

  return t('Migrated @m view blocks; rewrote @r layout placements; @rv view blocks and @rr inline_block:view references remain.', [
    '@m' => $migrated,
    '@r' => $rewritten,
    '@rv' => $remaining_views,
    '@rr' => $remaining_refs,
  ]);
}

/**
 * Supersedes the predecessor listing blocks (post_list/event_list/directory).
 *
 * Converts each predecessor block instance in place to the equivalent new
 * listing bundle and pre-fills field_view_params with params reproducing the
 * predecessor View's query (ADR DR-10), then rewrites the predecessor inline
 * placements across all revisions. Idempotent and pre-flight logged like
 * deploy_10001, and batched the same way. The predecessor bundles and their
 * embedded Views are removed separately, only after this migration is
 * validated on staging (#1171).
 */
function ys_views_basic_deploy_10002(&$sandbox) {
  /** @var \Drupal\ys_views_basic\Service\ListingMigrator $migrator */
  $migrator = \Drupal::service('ys_views_basic.listing_migrator');
  $logger = \Drupal::logger('ys_views_basic');
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  $legacy_bundles = ['post_list', 'event_list', 'directory'];
  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = [];
    $sandbox['counts'] = [];
    foreach ($legacy_bundles as $legacy) {
      if (!ViewsBasicManager::predecessorPreset($legacy)) {
        continue;
      }
      $ids = $block_storage->getQuery()
        ->condition('type', $legacy)
        ->accessCheck(FALSE)
        ->execute();
      $sandbox['ids'] = array_merge($sandbox['ids'], array_values($ids));
      $sandbox['counts'][$legacy] = 0;
    }
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['blocks_done'] = FALSE;
    $sandbox['migrated'] = 0;
    $sandbox['placements'] = [];
  }

  if (!$sandbox['blocks_done']) {
    $chunk = array_splice($sandbox['ids'], 0, ListingMigrator::BATCH_SIZE);
    foreach ($block_storage->loadMultiple($chunk) as $block) {
      $legacy = $block->bundle();
      $preset = ViewsBasicManager::predecessorPreset($legacy);
      if (!$preset) {
        continue;
      }
      $block_id = $block->id();
      $block->set('type', $preset['target']);
      $block->save();
      // Reload so the new bundle's field list (which includes
      // field_view_params, absent on the predecessor bundle) is available,
      // then pre-fill the params that reproduce the predecessor View's query.
      $block = $block_storage->load($block_id);
      $block->set('field_view_params', ['params' => json_encode($preset['params'])]);
      $block->save();
      // A bundle swap leaves prior field-table rows stamped with the old
      // bundle; patch every block_content field table for this entity.
      $migrator->patchBlockFieldBundles($block_id, $preset['target']);
      $sandbox['counts'][$legacy]++;
      $sandbox['migrated']++;
    }
    if ($chunk) {
      $block_storage->resetCache($chunk);
    }

    if ($sandbox['ids']) {
      $sandbox['#finished'] = 0.5 * ($sandbox['total'] - count($sandbox['ids'])) / $sandbox['total'];
      return NULL;
    }
    $sandbox['blocks_done'] = TRUE;
    foreach ($sandbox['counts'] as $legacy => $count) {
      if ($count) {
        $logger->notice('Predecessor migration: converted @n "@legacy" blocks to @target.', [
          '@n' => $count,
          '@legacy' => $legacy,
          '@target' => ViewsBasicManager::predecessorPreset($legacy)['target'],
        ]);
      }
    }
  }

  // Rewrite the predecessor inline-block placements across all revisions.
  if (!$migrator->rewritePlacements($legacy_bundles, $sandbox['placements'])) {
    $sandbox['#finished'] = 0.5;
    return NULL;
  }

  // Clear caches so the rewritten layouts render with the new bundles.
  \Drupal::service('cache.render')->invalidateAll();
  $migrator->clearLayoutTempstore($sandbox['placements']['entities']);
  $sandbox['#finished'] = 1;

  return t('Predecessor migration: converted @m blocks; rewrote @r layout placements.', [
    '@m' => $sandbox['migrated'],
    '@r' => $sandbox['placements']['rewritten'],
  ]);
}

/**
 * Migrates resource_view blocks onto the resource listing bundles (#1723).
 *
 * The ys_views_content_resources module's resource_view block is replaced by
 * the resource_card, resource_portrait_grid, resource_list_item and
 * resource_condensed bundles, served by ys_views_basic. Each block is
 * re-bundled in place to the bundle for its current design option, and its
 * params are copied from field_view_resource_params to field_view_params on
 * EVERY revision, normalised onto ys_views_basic's keys
 * (ViewsBasicManager::normalizeResourceParams()). All revisions matter
 * because Layout Builder renders an inline block by revision id: a published
 * page with a pending draft points at an older block revision.
 *
 * The old field is left in place (it is deleted with the old module), so the
 * source data survives until then. Idempotent: a second run finds no
 * resource_view blocks and changes nothing. Blocks whose params name no
 * design option are skipped and logged, as deploy_10001 does. Batched like
 * deploy_10001.
 */
function ys_views_basic_deploy_10003(&$sandbox) {
  /** @var \Drupal\ys_views_basic\Service\ListingMigrator $migrator */
  $migrator = \Drupal::service('ys_views_basic.listing_migrator');
  $logger = \Drupal::logger('ys_views_basic');
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  if (!isset($sandbox['ids'])) {
    $ids = $block_storage->getQuery()
      ->condition('type', 'resource_view')
      ->accessCheck(FALSE)
      ->execute();
    $logger->notice('Resource migration pre-flight: @count "resource_view" blocks to evaluate.', ['@count' => count($ids)]);
    $sandbox['ids'] = array_values($ids);
    $sandbox['total'] = count($ids);
    $sandbox['blocks_done'] = FALSE;
    $sandbox['migrated'] = 0;
    $sandbox['placements'] = [];
  }

  if (!$sandbox['blocks_done']) {
    $chunk = array_splice($sandbox['ids'], 0, ListingMigrator::BATCH_SIZE);
    foreach ($block_storage->loadMultiple($chunk) as $block) {
      // Read the params before the bundle changes: the target bundles do not
      // carry field_view_resource_params.
      $field = $block->get('field_view_resource_params');
      $params = $field->isEmpty() ? NULL : ($field->first()->getValue()['params'] ?? NULL);
      $decoded = $params ? json_decode($params, TRUE) : NULL;
      $view_mode = is_array($decoded) ? ($decoded['view_mode'] ?? NULL) : NULL;
      $target = ViewsBasicManager::migrationTargetBundle(ViewsBasicManager::CONTENT_TYPE_RESOURCE, $view_mode);

      if ($target === NULL) {
        $logger->warning('Resource migration: skipping block @id with unmappable view_mode=@v.', [
          '@id' => $block->id(),
          '@v' => $view_mode ?? 'NULL',
        ]);
        continue;
      }

      $block_id = $block->id();
      $block->set('type', $target);
      $block->save();
      $migrator->copyResourceParams($block_id, $target);
      $migrator->patchBlockFieldBundles($block_id, $target);
      $sandbox['migrated']++;
    }
    // Frees the chunk, and drops the cached copies: the copy wrote field
    // tables directly, past the entity caches. (With no ids, resetCache()
    // would flush the whole storage instead.)
    if ($chunk) {
      $block_storage->resetCache($chunk);
    }

    if ($sandbox['ids']) {
      $sandbox['#finished'] = 0.5 * ($sandbox['total'] - count($sandbox['ids'])) / $sandbox['total'];
      return NULL;
    }
    $sandbox['blocks_done'] = TRUE;
  }

  if (!$migrator->rewritePlacements(['resource_view'], $sandbox['placements'])) {
    $sandbox['#finished'] = 0.5;
    return NULL;
  }
  $migrated = $sandbox['migrated'];
  $rewritten = $sandbox['placements']['rewritten'];

  $remaining = (int) $block_storage->getQuery()
    ->condition('type', 'resource_view')
    ->accessCheck(FALSE)
    ->count()
    ->execute();
  $logger->notice('Resource migration complete: @m blocks migrated, @r placements rewritten, @rv "resource_view" blocks remain.', [
    '@m' => $migrated,
    '@r' => $rewritten,
    '@rv' => $remaining,
  ]);

  \Drupal::service('cache.render')->invalidateAll();
  $migrator->clearLayoutTempstore($sandbox['placements']['entities']);
  $sandbox['#finished'] = 1;

  return t('Resource migration: converted @m blocks; rewrote @r layout placements; @rv resource_view blocks remain.', [
    '@m' => $migrated,
    '@r' => $rewritten,
    '@rv' => $remaining,
  ]);
}

/**
 * Turns on the predecessor filters for blocks 10002 already migrated (#1967).
 *
 * The first version of deploy_10002 converted Post Feed, Calendar List and
 * Directory blocks without the exposed filter visitors used on them. A block
 * whose params still equal that filterless preset gets the current preset
 * (ViewsBasicManager::predecessorPreset()), which adds the filter, its live
 * label, and the "only terms in use" option. A block an editor has changed
 * since is left alone. Idempotent: a second run finds no filterless preset.
 * On a site where 10002 runs with the current preset, this finds nothing.
 */
function ys_views_basic_deploy_10004(&$sandbox) {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  if (!isset($sandbox['ids'])) {
    // Target bundle => predecessor bundle.
    $sandbox['legacy'] = [];
    foreach (['post_list', 'event_list', 'directory'] as $legacy) {
      $sandbox['legacy'][ViewsBasicManager::predecessorPreset($legacy)['target']] = $legacy;
    }
    $sandbox['ids'] = array_values($block_storage->getQuery()
      ->condition('type', array_keys($sandbox['legacy']), 'IN')
      ->accessCheck(FALSE)
      ->execute());
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['updated'] = 0;
  }

  $chunk = array_splice($sandbox['ids'], 0, ListingMigrator::BATCH_SIZE);
  foreach ($block_storage->loadMultiple($chunk) as $block) {
    $legacy = $sandbox['legacy'][$block->bundle()];
    $field = $block->get('field_view_params');
    $params = $field->isEmpty() ? NULL : json_decode($field->first()->getValue()['params'] ?? '', TRUE);
    // Loose comparison: the same keys and values in any order.
    if ($params != ViewsBasicManager::filterlessPredecessorParams($legacy)) {
      continue;
    }
    $block->set('field_view_params', ['params' => json_encode(ViewsBasicManager::predecessorPreset($legacy)['params'])]);
    $block->save();
    $sandbox['updated']++;
  }
  if ($chunk) {
    $block_storage->resetCache($chunk);
  }

  if ($sandbox['ids']) {
    $sandbox['#finished'] = ($sandbox['total'] - count($sandbox['ids'])) / $sandbox['total'];
    return NULL;
  }
  if ($sandbox['updated']) {
    \Drupal::service('cache.render')->invalidateAll();
  }
  $sandbox['#finished'] = 1;
  \Drupal::logger('ys_views_basic')->notice('Predecessor filters: turned on the exposed filter for @n migrated blocks.', ['@n' => $sandbox['updated']]);
  return t('Predecessor filters: turned on the exposed filter for @n migrated blocks.', ['@n' => $sandbox['updated']]);
}
