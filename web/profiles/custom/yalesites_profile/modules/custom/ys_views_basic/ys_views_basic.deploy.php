<?php

/**
 * @file
 * Contains ys_views_basic.deploy.php.
 */

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
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
  // Clear Layout Builder tempstore overrides.
  if (\Drupal::database()->schema()->tableExists('key_value_expire')) {
    \Drupal::database()->delete('key_value_expire')
      ->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides')
      ->execute();
  }
}

/**
 * Migrates legacy "view" blocks to per-(content type, display mode) bundles.
 *
 * In-place bundle swap keyed on the stored content type and view mode together
 * (ADR DR-9, ticket #1169): e.g. {post, card} -> post_card. Because every new
 * bundle shares the existing field_view_params storage (DR-3) this copies no
 * data. The hook is idempotent (blocks already in a target bundle are skipped),
 * pre-flight counts and logs the work, rewrites inline_block:view plugin IDs
 * across ALL revisions, and verifies zero remaining "view" blocks/references.
 */
function ys_views_basic_deploy_10001() {
  $logger = \Drupal::logger('ys_views_basic');
  $entity_type_manager = \Drupal::entityTypeManager();
  $block_storage = $entity_type_manager->getStorage('block_content');
  $database = \Drupal::database();

  // --- Pre-flight ---------------------------------------------------------.
  $view_ids = $block_storage->getQuery()
    ->condition('type', 'view')
    ->accessCheck(FALSE)
    ->execute();
  $logger->notice('View migration pre-flight: @count "view" blocks to evaluate.', ['@count' => count($view_ids)]);

  // Inline-only placements are expected (DR-9/DR-12). Warn if any reusable
  // (block_content:) placement of a listing exists, since this migration does
  // not rewrite those.
  $reusable = _ys_views_basic_count_placements($database, 'block_content:');
  if ($reusable > 0) {
    $logger->warning('View migration: found @n layout rows containing reusable (block_content:) placements; verify these on staging.', ['@n' => $reusable]);
  }

  // --- In-place bundle swap ----------------------------------------------.
  $distribution = [];
  $migrated = 0;
  foreach ($block_storage->loadMultiple($view_ids) as $block) {
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
    $block->save();

    // A bundle swap + save leaves prior field-table rows stamped "view"; patch
    // the bundle column on the data and revision tables. All revisions of a
    // migrated block belong to the new bundle: the target is derived from the
    // block's current params, one target per block (ADR DR-9).
    foreach (['block_content__field_view_params', 'block_content_revision__field_view_params'] as $table) {
      if ($database->schema()->tableExists($table)) {
        $database->update($table)
          ->fields(['bundle' => $target])
          ->condition('entity_id', $block->id())
          ->execute();
      }
    }

    $distribution["$type/$view_mode"] = ($distribution["$type/$view_mode"] ?? 0) + 1;
    $migrated++;
  }
  $logger->notice('View migration: swapped @n blocks. Distribution: @dist', [
    '@n' => $migrated,
    '@dist' => json_encode($distribution),
  ]);

  // --- Rewrite inline_block:view placements across ALL revisions ----------.
  // The rewrite reads each referenced block's CURRENT bundle rather than a
  // per-run map, so it is idempotent: re-running after an interrupted deploy
  // still rewrites any placement whose block has already been re-bundled.
  $rewritten = _ys_views_basic_rewrite_placements($database, ['view'], $logger);

  // --- Post-run verification ---------------------------------------------.
  $remaining_views = (int) $block_storage->getQuery()
    ->condition('type', 'view')
    ->accessCheck(FALSE)
    ->count()
    ->execute();
  $remaining_refs = _ys_views_basic_count_placements($database, 'inline_block:view"');
  $logger->notice('View migration complete: @m blocks migrated, @r placements rewritten. Remaining "view" blocks: @rv; remaining inline_block:view references: @rr.', [
    '@m' => $migrated,
    '@r' => $rewritten,
    '@rv' => $remaining_views,
    '@rr' => $remaining_refs,
  ]);

  // Clear caches so the rewritten layouts render with the new bundles.
  \Drupal::service('cache.render')->invalidateAll();
  if ($database->schema()->tableExists('key_value_expire')) {
    $database->delete('key_value_expire')
      ->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides')
      ->execute();
  }

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
 * deploy_10001. The predecessor bundles and their embedded Views are removed
 * separately, only after this migration is validated on staging (#1171).
 */
function ys_views_basic_deploy_10002() {
  $logger = \Drupal::logger('ys_views_basic');
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $database = \Drupal::database();

  $legacy_bundles = ['post_list', 'event_list', 'directory'];
  $migrated = 0;
  foreach ($legacy_bundles as $legacy) {
    $preset = ViewsBasicManager::predecessorPreset($legacy);
    if (!$preset) {
      continue;
    }
    $ids = $block_storage->getQuery()
      ->condition('type', $legacy)
      ->accessCheck(FALSE)
      ->execute();
    $count = 0;
    foreach ($block_storage->loadMultiple($ids) as $block) {
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
      _ys_views_basic_patch_block_field_bundles($database, $block_id, $preset['target']);
      $count++;
      $migrated++;
    }
    if ($count) {
      $logger->notice('Predecessor migration: converted @n "@legacy" blocks to @target.', [
        '@n' => $count,
        '@legacy' => $legacy,
        '@target' => $preset['target'],
      ]);
    }
  }

  // Rewrite the predecessor inline-block placements across all revisions.
  $rewritten = _ys_views_basic_rewrite_placements($database, $legacy_bundles, $logger);

  // Clear caches so the rewritten layouts render with the new bundles.
  \Drupal::service('cache.render')->invalidateAll();
  if ($database->schema()->tableExists('key_value_expire')) {
    $database->delete('key_value_expire')
      ->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides')
      ->execute();
  }

  return t('Predecessor migration: converted @m blocks; rewrote @r layout placements.', [
    '@m' => $migrated,
    '@r' => $rewritten,
  ]);
}

/**
 * Patches the bundle column on every block_content field table for an entity.
 *
 * A bundle swap + save updates the base table but leaves dedicated field-table
 * rows stamped with the old bundle. Field values still load by entity id, but
 * the bundle column is patched for consistency with bundle-scoped queries.
 *
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 * @param int|string $entity_id
 *   The block content entity id.
 * @param string $target
 *   The new bundle id.
 */
function _ys_views_basic_patch_block_field_bundles($database, $entity_id, string $target): void {
  $field_map = \Drupal::service('entity_field.manager')->getFieldMap()['block_content'] ?? [];
  foreach (array_keys($field_map) as $field_name) {
    foreach (["block_content__$field_name", "block_content_revision__$field_name"] as $table) {
      if ($database->schema()->tableExists($table) && $database->schema()->fieldExists($table, 'bundle')) {
        $database->update($table)
          ->fields(['bundle' => $target])
          ->condition('entity_id', $entity_id)
          ->execute();
      }
    }
  }
}

/**
 * Returns the layout_builder__layout tables (data + revision) that exist.
 *
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 *
 * @return array
 *   A list of existing table names holding serialized Section blobs.
 */
function _ys_views_basic_layout_tables($database): array {
  $entity_field_manager = \Drupal::service('entity_field.manager');
  $tables = [];
  foreach ($entity_field_manager->getFieldMap() as $entity_type_id => $fields) {
    if (!isset($fields['layout_builder__layout'])) {
      continue;
    }
    foreach (["{$entity_type_id}__layout_builder__layout", "{$entity_type_id}_revision__layout_builder__layout"] as $table) {
      if ($database->schema()->tableExists($table)) {
        $tables[] = $table;
      }
    }
  }
  return $tables;
}

/**
 * Counts layout rows whose serialized section contains a needle.
 *
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 * @param string $needle
 *   The substring to look for in the serialized section blob.
 *
 * @return int
 *   The number of matching rows.
 */
function _ys_views_basic_count_placements($database, string $needle): int {
  $count = 0;
  foreach (_ys_views_basic_layout_tables($database) as $table) {
    $count += (int) $database->select($table, 't')
      ->condition('t.layout_builder__layout_section', '%' . $database->escapeLike($needle) . '%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
  }
  return $count;
}

/**
 * Rewrites legacy inline-block plugin IDs to their migrated target bundle.
 *
 * Walks every layout_builder__layout row (data and revision tables), and for
 * each component whose plugin id is one of the given legacy ids
 * (inline_block:<legacy>), loads the referenced block revision and — if that
 * block has been re-bundled to a listing bundle — rewrites the id to
 * inline_block:<bundle>. Reading the block's current bundle (rather than a
 * per-run map) makes this idempotent and covers all revisions, so publishing an
 * old draft cannot resurrect a legacy plugin id pointing at a re-bundled block
 * (ADR DR-9/DR-10). Mirrors deploy_10000's approach.
 *
 * @param \Drupal\Core\Database\Connection $database
 *   The database connection.
 * @param array $legacy_bundles
 *   Legacy block content bundle ids whose inline_block:<bundle> placements
 *   should be rewritten (e.g. ['view'] or ['post_list', 'event_list']).
 * @param \Psr\Log\LoggerInterface $logger
 *   The logger.
 *
 * @return int
 *   The number of layout rows rewritten.
 */
function _ys_views_basic_rewrite_placements($database, array $legacy_bundles, $logger): int {
  $rewritten = 0;
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $listing_bundles = ViewsBasicManager::LISTING_BUNDLES;
  $legacy_ids = array_map(fn($b) => 'inline_block:' . $b, $legacy_bundles);

  foreach (_ys_views_basic_layout_tables($database) as $table) {
    // Select rows mentioning any of the legacy plugin ids.
    $query = $database->select($table, 't')->fields('t');
    $group = $query->orConditionGroup();
    foreach ($legacy_ids as $legacy_id) {
      $group->condition('t.layout_builder__layout_section', '%' . $database->escapeLike($legacy_id . '"') . '%', 'LIKE');
    }
    $rows = $query->condition($group)->execute();

    foreach ($rows as $row) {
      $section = @unserialize($row->layout_builder__layout_section, [
        'allowed_classes' => [Section::class, SectionComponent::class],
      ]);
      if (!$section instanceof Section) {
        continue;
      }
      $changed = FALSE;
      foreach ($section->getComponents() as $component) {
        $config = $component->get('configuration');
        if (!in_array($config['id'] ?? NULL, $legacy_ids, TRUE)) {
          continue;
        }
        $rid = $config['block_revision_id'] ?? NULL;
        $block = $rid ? $block_storage->loadRevision($rid) : NULL;
        if ($block && isset($listing_bundles[$block->bundle()])) {
          $config['id'] = 'inline_block:' . $block->bundle();
          $component->setConfiguration($config);
          $changed = TRUE;
        }
        else {
          // A legacy placement whose block was not migrated (e.g. an
          // orphaned/deleted block revision). Flag it for manual follow-up
          // rather than silently leaving it.
          $logger->warning('Listing migration: unrewritten @id placement in @table entity @e revision @r references block revision @rid.', [
            '@id' => $config['id'] ?? 'NULL',
            '@table' => $table,
            '@e' => $row->entity_id,
            '@r' => $row->revision_id,
            '@rid' => $rid ?? 'NULL',
          ]);
        }
      }
      if (!$changed) {
        continue;
      }
      // Match the exact row read, including langcode/deleted, so the update is
      // unambiguous even if layouts ever become translatable.
      $database->update($table)
        ->fields(['layout_builder__layout_section' => serialize($section)])
        ->condition('entity_id', $row->entity_id)
        ->condition('revision_id', $row->revision_id)
        ->condition('delta', $row->delta)
        ->condition('langcode', $row->langcode)
        ->condition('deleted', $row->deleted)
        ->execute();
      $rewritten++;
    }
  }

  if ($rewritten) {
    $logger->notice('Listing migration: rewrote @n layout rows referencing @ids.', [
      '@n' => $rewritten,
      '@ids' => implode(', ', $legacy_ids),
    ]);
  }
  return $rewritten;
}
