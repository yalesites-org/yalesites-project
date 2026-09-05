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
 * Implements hook_deploy_NAME().
 *
 * Repairs Resource text values whose stored text format the field forbids.
 *
 * field_abstract, field_citation and field_content_description have declared
 * `allowed_formats: [restricted_html]` since the day they were created
 * (YISP-101); the restriction was never narrowed after the fact. Content
 * written by a third-party migration bypassed the platform's own importer
 * (ResourceImportService::textFormat(), which derives the format from
 * allowed_formats) and stored some other format instead.
 *
 * Core's \Drupal\filter\Element\TextFormat::processFormat() intersects the
 * user's usable formats with the field's allowed_formats and then tests the
 * stored format against that intersection, so those values disable the widget
 * outright — "This field has been disabled because you do not have sufficient
 * permissions to edit it." — for every user without 'administer filters'. No
 * YaleSites role holds that permission (it allows creating arbitrary text
 * formats, a stored-XSS vector), so core's intended remedy of "an
 * administrator reassigns the format" is unavailable through the UI and has to
 * happen here.
 *
 * Only the format name changes; the stored markup is untouched, so the repair
 * is reversible. Values authored under a more permissive format may render
 * with less markup afterwards — restricted_html is what these fields were
 * always contracted to render as, so this brings rendering into line with the
 * field's configuration rather than away from it.
 *
 * The three fields are named explicitly rather than discovered from config.
 * Discovery would also sweep in field_teaser_text, whose contract is
 * heading_html — a NARROWER format that permits neither <a> nor <br>, so a
 * link in a Resource teaser would stop rendering as a link. That is a
 * different, wider change than this report describes and is left for a human
 * to decide on.
 *
 * The repair writes the format column directly instead of re-saving nodes.
 * content_moderation's presave handler rewrites publication status whenever a
 * revision's stored status disagrees with its moderation state, and that
 * branch is NOT inside its isSyncing() guard, so re-saving every Resource
 * revision could silently unpublish live pages wherever an import left that
 * divergence behind. A column write cannot create revisions, change the
 * default revision, alter moderation state, or bump 'changed'.
 *
 * @see \Drupal\content_moderation\Entity\Handler\ModerationHandler::onPresave()
 * @see yalesites-org/YaleSites-Internal#1646
 */
function ys_core_deploy_10008() {
  /** @var \Drupal\ys_core\TextFormatRepair $repair */
  $repair = \Drupal::service('ys_core.text_format_repair');

  $repaired = 0;
  foreach (['field_abstract', 'field_citation', 'field_content_description'] as $field_name) {
    $repaired += $repair->repairFieldStorage('node', 'resource', $field_name);
  }

  if ($repaired === 0) {
    return t('No Resource values had an out-of-contract text format.');
  }

  return t('Repaired the stored text format on @count Resource field value(s).', ['@count' => $repaired]);
}
