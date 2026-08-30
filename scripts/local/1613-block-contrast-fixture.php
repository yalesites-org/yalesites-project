<?php

/**
 * @file
 * Builds the per-block fixture the #1613 audit photographs.
 *
 * Local audit fixture, not platform code -- run with:
 *   lando drush php:script scripts/local/1613-block-contrast-fixture.php
 *
 * Companion to 1613-section-contrast-fixture.php, which proves the SECTION
 * backgrounds paint. This one proves what a BLOCK looks like sitting on each
 * of them, which is what the contrast audit is actually about.
 *
 * Rather than construct each block type's fields by hand -- several need
 * media, link and entity-reference values -- it clones a block that already
 * exists on this site and drops the clone into a themed section. The visual
 * regression site ships real content for every block type, so the clone is
 * representative content rather than a synthetic stub.
 *
 * Layout: one node per section type, each holding
 * (one block type x six section themes) sections, so a single full-page
 * capture per global theme covers every audited block on every background.
 * That is 7 captures per section type per before/after state instead of one
 * per (block x background x theme).
 */

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;

$themes = ['one', 'two', 'three', 'four', 'five', 'six'];

/**
 * Block types this run changes, and the component each one renders through.
 *
 * Deliberately NOT the full 27: accordion, link_grid and wrapped_text_callout
 * are the subject of the open PR component-library-twig#705 (#1614) and are
 * left alone so this work does not conflict with it.
 */
$blockTypes = [
  'custom_cards' => '02-molecules/cards/custom-card',
  'directory' => '02-molecules/cards/directory-listing-card',
  'reference_card' => '02-molecules/cards/reference-card',
  'wrapped_image' => '02-molecules/wrapped-image',
  'content_spotlight_portrait' => '02-molecules/content-spotlight-portrait',
  // Not changed by this run, but included because they consume properties the
  // shared `_yds-layout.scss` rule re-points: the CTA atom draws its fill and
  // border from `--color-layout-border`, and the divider atom from
  // `--color-divider`. Both therefore need before/after evidence that the
  // shared change did not regress them.
  'button_link' => '01-atoms/controls/cta',
  'divider' => '01-atoms/divider',
];

$storage = \Drupal::entityTypeManager()->getStorage('block_content');

// One representative source block per type, oldest first so re-runs pick the
// same one and before/after captures stay comparable.
$sources = [];
foreach (array_keys($blockTypes) as $type) {
  $ids = \Drupal::entityQuery('block_content')
    ->accessCheck(FALSE)
    ->condition('type', $type)
    ->sort('id')
    ->range(0, 1)
    ->execute();

  if (!$ids) {
    printf("SKIP %s: no existing block to clone\n", $type);
    continue;
  }

  $sources[$type] = $storage->load(reset($ids));
}

$fixtures = [
  '1613 Block contrast - One column' => [
    'layout' => 'layout_onecol',
    'region' => 'content',
  ],
  '1613 Block contrast - Two Column 70-30' => [
    'layout' => 'ys_layout_two_column',
    'region' => 'content',
  ],
];

foreach ($fixtures as $title => $spec) {
  $existing = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['title' => $title]);
  foreach ($existing as $node) {
    $node->delete();
  }

  $sections = [];

  foreach ($sources as $type => $source) {
    foreach ($themes as $theme) {
      // createDuplicate() gives an unsaved copy sharing the original's field
      // values, so the clone is never entangled with the source block's own
      // placements.
      $clone = $source->createDuplicate();
      $clone->set('info', sprintf('1613 %s - %s - %s', $type, $theme, $spec['layout']));
      $clone->set('reusable', FALSE);
      $clone->save();

      $section = new Section($spec['layout'], [
        'label' => sprintf('%s / theme %s', $type, $theme),
        'theme' => $theme,
        'divider' => 0,
      ]);
      $section->appendComponent(new SectionComponent(
        \Drupal::service('uuid')->generate(),
        $spec['region'],
        [
          'id' => 'inline_block:' . $type,
          'label' => sprintf('%s %s', $type, $theme),
          'label_display' => FALSE,
          'block_revision_id' => $clone->getRevisionId(),
          'block_serialized' => serialize($clone),
        ]
      ));

      $sections[] = $section;
    }
  }

  // 'page' is under content moderation, so 'status' alone leaves the node in
  // 'draft' and anonymous requests 403.
  $node = Node::create([
    'type' => 'page',
    'title' => $title,
    'moderation_state' => 'published',
    'uid' => 1,
  ]);
  $node->set('layout_builder__layout', $sections);
  $node->save();

  printf("%s => /node/%d (%d sections)\n", $title, $node->id(), count($sections));
}
