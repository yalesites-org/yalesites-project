<?php

/**
 * @file
 * Post update functions for ys_core module.
 */

use Drupal\taxonomy\Entity\Term;

/**
 * Implements hook_deploy_NAME().
 *
 * Sets the default taxonomy of custom_vocab if it is NULL.
 */
function ys_core_deploy_10001() {
  $vocab = \Drupal::configFactory()->getEditable('taxonomy.vocabulary.custom_vocab');
  if ($vocab && $vocab->get('name') === NULL) {
    $vocab->set('name', 'Custom Vocab')->save();

    $content_types = ['event', 'page', 'post', 'profile', 'resource'];

    foreach ($content_types as $content_type) {
      \Drupal::configFactory()->getEditable("field.field.node.{$content_type}.field_custom_vocab")
        ->set('label', 'Custom Vocab')
        ->save();
    }

    \Drupal::cache('discovery')->invalidateAll();
  }
}

/**
 * Implements hook_update().
 *
 * Converts field_style_variation settings to field_focus.
 */
function ys_core_deploy_10002() {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');
  $query = $block_storage->getQuery();
  $query->accessCheck(FALSE)
    ->condition('type', 'content_spotlight');

  $ids = $query->execute();

  foreach ($ids as $id) {
    $block = $block_storage->load($id);
    $latestRevisionId = $block_storage->getLatestRevisionId($id);

    if (!$latestRevisionId) {
      $latestRevision = $block_storage->createRevision($block);
    }
    else {
      $latestRevision = $block_storage->loadRevision($latestRevisionId);
    }

    $field_style_variation = $latestRevision->get('field_style_variation');

    if ($field_style_variation) {
      $latestRevision->set('field_focus', $field_style_variation->value);
      $latestRevision->save();
    }
  }

}

/**
 * Implements hook_update().
 *
 * Transforms the values currently in field_hide_sharing_links
 * to field_show_social_media_sharing for a post node.
 *
 * Remember to later remove the field_hide_sharing_links field.
 */
function ys_core_deploy_10003() {
  $ids = \Drupal::entityQuery('node')
    ->condition('type', 'post')
    ->accessCheck(FALSE)
    ->execute();

  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids);

  foreach ($nodes as $node) {
    if (!$node->hasField('field_hide_sharing_links')) {
      continue;
    }

    $node->set(
      'field_show_social_media_sharing',
      $node->get('field_hide_sharing_links')->value == '0' ? '1' : '0'
    );

    $node->save();
  }
}

/**
 * Implements hook_deploy_NAME().
 *
 * Updates the field_custom_vocab label for resources content type
 * to match the current custom_vocab_name setting.
 */
function ys_core_deploy_10004() {
  // Get the current custom vocabulary name from site settings.
  $custom_vocab_name = \Drupal::config('ys_core.site')->get('taxonomy.custom_vocab_name') ?? 'Custom Vocab';

  // Update the field label for the resource content type.
  $field_config = \Drupal::configFactory()->getEditable('field.field.node.resource.field_custom_vocab');

  if (!$field_config->isNew()) {
    $field_config->set('label', $custom_vocab_name)->save();

    // Clear cache so the new label is reflected.
    \Drupal::service('cache.discovery')->invalidateAll();

    \Drupal::messenger()->addStatus(t('Updated field_custom_vocab label for resources to "@label".', ['@label' => $custom_vocab_name]));
  }
}

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds Academic Years vocabulary with year-range terms if they don't exist.
 *
 * Runs after config import so the vocabulary is guaranteed to be present.
 * The update hook (ys_core_update_10010) fires before config import in
 * drush deploy and skips on fresh multidevs where the vocab doesn't exist yet.
 */
function ys_core_deploy_10005() {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $vocabulary_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary');

  if (!$vocabulary_storage->load('academic_years')) {
    return t('Academic Years vocabulary does not exist; skipping.');
  }

  $existing_terms = $term_storage->loadByProperties(['vid' => 'academic_years']);
  $existing_names = array_map(fn($term) => $term->getName(), $existing_terms);

  $weight = 0;
  $created = 0;
  for ($start_year = 2026; $start_year >= 2000; $start_year--) {
    $term_name = $start_year . '-' . ($start_year + 1);
    if (!in_array($term_name, $existing_names)) {
      Term::create([
        'vid' => 'academic_years',
        'name' => $term_name,
        'weight' => $weight,
      ])->save();
      $created++;
    }
    $weight++;
  }

  if ($created === 0) {
    return t('All Academic Years terms already exist; nothing to create.');
  }

  return t('Populated Academic Years vocabulary with @count terms.', ['@count' => $created]);
}

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds DCN Types vocabulary with default identifier type terms if they don't
 * exist.
 *
 * Runs after config import so the vocabulary is guaranteed to be present.
 * The update hook (ys_core_update_10012) fires before config import in
 * drush deploy and skips on fresh multidevs where the vocab doesn't exist yet.
 */
function ys_core_deploy_10006() {
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

  if (!\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load('dcn_types')) {
    return t('DCN Types vocabulary does not exist; skipping.');
  }

  $existing_names = array_map(
    fn($term) => $term->getName(),
    $term_storage->loadByProperties(['vid' => 'dcn_types'])
  );

  $default_terms = ['DOI', 'ISBN', 'ISSN', 'Report Number'];
  $created = 0;

  foreach ($default_terms as $weight => $term_name) {
    if (!in_array($term_name, $existing_names)) {
      Term::create([
        'vid' => 'dcn_types',
        'name' => $term_name,
        'weight' => $weight,
      ])->save();
      $created++;
    }
  }

  if ($created === 0) {
    return t('All DCN Types terms already exist; nothing to create.');
  }

  return t('Populated DCN Types vocabulary with @count terms.', ['@count' => $created]);
}

/**
 * Implements hook_deploy_NAME().
 *
 * Backfills field_icon on In-Line Message blocks that predate the field.
 *
 * Before issue #697 the component hardcoded its icon, so every In-Line Message
 * rendered 'circle-info' (the 'circle-exclamation' branch keyed off
 * inline_message__type, which atomic never passes, so it only ever fired for
 * the bare component in Storybook). Now that the icon is an editor-chosen
 * field, an empty value renders no icon at all — so without this backfill every
 * block created before the field existed would silently lose its icon.
 *
 * Drupal applies a field's default value only when an entity is created, never
 * retroactively, hence the explicit pass over existing blocks.
 *
 * Runs after config import so field_icon is guaranteed to exist; an update hook
 * would fire before the field config lands and quietly match nothing.
 *
 * Every empty value at this point predates the field — the field ships in this
 * same deploy, so no editor has yet had the chance to choose "- None -" — which
 * is why an empty value can safely be treated as "never set" rather than as a
 * deliberate text-only choice.
 *
 * Every revision is visited, not just the latest. Layout Builder renders the
 * revision a node revision pins (InlineBlock::getEntity() calls
 * loadRevision($configuration['block_revision_id'])), and
 * layout_builder__layout is itself revisionable — so a page with an unsaved
 * draft pins one block revision on the draft and an older one on the published
 * revision. Backfilling only the latest would write to the draft's revision and
 * leave the live page iconless; reverting a node would resurrect an untouched
 * revision for the same reason.
 *
 * Batched via the Sandbox API rather than ys_core_set_block_field_defaults():
 * that helper walks every block of a bundle in one pass, which is exactly what
 * timed out on sites with thousands of blocks and why ys_core_update_10006 and
 * 10007 were converted to sandboxes (commit a6fad6354). In-Line Message is a
 * Layout Builder inline block, so its count scales with pages.
 */
function ys_core_deploy_10007(&$sandbox) {
  $block_storage = \Drupal::entityTypeManager()->getStorage('block_content');

  if (!isset($sandbox['processed'])) {
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('block_content', 'inline_message');
    if (!isset($field_definitions['field_icon'])) {
      $sandbox['#finished'] = 1;
      return t('In-Line Message has no icon field; skipping.');
    }

    $sandbox['processed'] = 0;
    $sandbox['backfilled'] = 0;
    $sandbox['total'] = $block_storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('type', 'inline_message')
      ->count()
      ->execute();

    if ($sandbox['total'] == 0) {
      $sandbox['#finished'] = 1;
      return t('No In-Line Message blocks found.');
    }
  }

  // Paged over every revision rather than filtered to the empty ones: this
  // pass fills those values in, so a filtered result set would shrink
  // underneath the offset and skip revisions. Sorting on revision_id keeps the
  // window stable, since neither it nor the bundle is touched here.
  // An allRevisions() query returns revision_id => entity_id.
  $result = $block_storage->getQuery()
    ->accessCheck(FALSE)
    ->allRevisions()
    ->condition('type', 'inline_message')
    ->sort('revision_id')
    ->range($sandbox['processed'], 50)
    ->execute();

  foreach (array_keys($result) as $revision_id) {
    $sandbox['processed']++;

    $block = $block_storage->loadRevision($revision_id);
    if (!$block || !$block->get('field_icon')->isEmpty()) {
      continue;
    }

    // The historical value the component hardcoded — deliberately a constant
    // rather than a read of the field's current default, so a later change to
    // that default cannot retroactively rewrite what these blocks used to show.
    $block->set('field_icon', 'circle-info');
    // Saving a loaded revision updates that revision in place rather than
    // creating a new one, so the id a layout pins to stays valid.
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
    return t('No In-Line Message blocks needed an icon backfill.');
  }

  return t('Backfilled the default icon on @count In-Line Message block revision(s).', ['@count' => $sandbox['backfilled']]);
}

/**
 * Normalises stored settings values that predate this module's config schema.
 *
 * Three kinds of value were being stored in a shape their own form never
 * produces, which went unnoticed because these objects had no config schema to
 * validate them against:
 *
 * - `custom_favicon` and `site_name_image` are managed_file values, so the
 *   element only ever writes a list of file IDs. Both shipped an empty string
 *   as their install default, so every site that never uploaded one still
 *   stores that scalar.
 * - `environment_indicator.show` ships boolean TRUE in config/install, but
 *   SiteSettingsForm used to store the checkbox's raw integer, so a site that
 *   saved the settings form before that cast landed stores 1 or 0.
 * - `focus_header_image` names a single media item, but some sites store an
 *   array of IDs - the shape ys_core_preprocess_region() has guarded against
 *   since a production hotfix. Unlike the other two this one is not merely
 *   untidy: it is the only legacy value the new schema makes unsaveable, so
 *   repairing it is what lets a deploy finish at all on those sites.
 *
 * This runs as a deploy hook rather than a hook_update_N because it writes to
 * active config: drush deploy runs updatedb BEFORE config:import, so a config
 * edit made at that point can be overwritten by the import that follows.
 * config_ignore does currently list `ys_core*`, which would spare these
 * objects, but depending on that is fragile - deploy:hook runs after the
 * import and is correct either way.
 *
 * The stale shapes would otherwise persist indefinitely. Saving
 * one of the settings forms would fix the value in passing - Config::save()
 * casts to the schema's types once a schema exists - but only partly: header
 * settings write `site_name_image` solely inside a
 * ys_core_allow_secret_items() check, so a site_admin saving that form leaves
 * the stale value untouched.
 *
 * Every reader of the two managed_file keys and the indicator flag only tests
 * truthiness, and an empty list is falsy exactly as the empty string was, so
 * this changes no behaviour for them. Clearing an array focus_header_image
 * matches what the render path already did with that value: it named no single
 * media item, so no image was ever shown for it.
 *
 * @param bool $dry_run
 *   When TRUE, report what would change without writing anything.
 *
 * @return array
 *   A report with a single 'changes' key holding one human-readable line per
 *   value rewritten.
 */
function ys_core_normalize_settings_shapes($dry_run = FALSE) {
  $changes = [];
  $config_factory = \Drupal::configFactory();

  // var_export() spreads an array over several lines, which would break the
  // one-line-per-change shape of the report.
  $format = fn ($value) => is_array($value) ? json_encode($value) : var_export($value, TRUE);
  $describe = fn ($name, $key, $from, $to) => sprintf(
    '%s:%s %s => %s',
    $name,
    $key,
    $format($from),
    $format($to)
  );

  // Every value is read before anything is written. Config::save() casts data
  // to the schema's types once a schema exists, so saving one key can silently
  // correct another - which would make a report built as we go under-count.
  $file_settings = [
    'ys_core.site' => 'custom_favicon',
    'ys_core.header_settings' => 'site_name_image',
  ];
  $planned = [];

  // A focus_header_image holding an array of media IDs rather than one ID is a
  // shape the render path already guards against (see
  // ys_core_preprocess_region()), and it is the one legacy value the schema
  // cannot tolerate: castValue() recurses into the array looking for
  // focus_header_image.0 under a string element and throws. That aborts ANY
  // save of ys_core.header_settings, this hook's own site_name_image write
  // included, so it is planned first and so repaired first.
  $focus_image = $config_factory->getEditable('ys_core.header_settings')->get('focus_header_image');
  if ($focus_image !== NULL && !is_scalar($focus_image)) {
    $planned[] = ['ys_core.header_settings', 'focus_header_image', $focus_image, NULL, TRUE];
  }

  foreach ($file_settings as $name => $key) {
    $value = $config_factory->getEditable($name)->get($key);
    // NULL means the key is absent from this site's stored config, which the
    // schema does not object to; an array is already the right shape.
    if ($value === NULL || is_array($value)) {
      continue;
    }
    // A numeric scalar is still a usable file ID, so keep it rather than
    // discarding a real upload. Anything else - notably the '' the install
    // default used to ship - names no file and becomes the empty list.
    $planned[] = [$name, $key, $value, is_numeric($value) ? [(int) $value] : [], FALSE];
  }

  $show = $config_factory->getEditable('ys_core.site')->get('environment_indicator.show');
  if ($show !== NULL && !is_bool($show)) {
    $planned[] = ['ys_core.site', 'environment_indicator.show', $show, (bool) $show, FALSE];
  }

  foreach ($planned as [$name, $key, $from, $to, $clear]) {
    if (!$dry_run) {
      if ($clear) {
        // Reuse the render path's clearing helper, silently: a deploy has no
        // interactive user to show its message to.
        _ys_core_clear_focus_header_image_config($name, FALSE);
      }
      else {
        $config_factory->getEditable($name)->set($key, $to)->save();
      }
    }
    $changes[] = $describe($name, $key, $from, $to);
  }

  return ['changes' => $changes];
}

/**
 * Implements hook_deploy_NAME().
 *
 * Normalises ys_core settings values that predate the module's config schema.
 */
function ys_core_deploy_10008() {
  $report = ys_core_normalize_settings_shapes();
  $logger = \Drupal::logger('ys_core');

  foreach ($report['changes'] as $change) {
    $logger->notice('Normalised stored ys_core settings value: @change', ['@change' => $change]);
  }

  return t('Normalised @count stored ys_core settings value(s) to the shape the config schema now declares.', [
    '@count' => count($report['changes']),
  ]);
}
