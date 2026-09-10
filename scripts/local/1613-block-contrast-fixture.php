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

use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;

$themes = ['one', 'two', 'three', 'four', 'five', 'six'];

/**
 * The block types this fixture renders.
 *
 * Deliberately NOT the full 27: accordion, link_grid and wrapped_text_callout
 * are the subject of the open PR component-library-twig#705 (#1614) and are
 * left alone so this work does not conflict with it.
 */
$blockTypes = [
  // Changed by this run.
  'custom_cards',
  'directory',
  'reference_card',
  'wrapped_image',
  'content_spotlight_portrait',
  // NOT changed, but included because they consume properties the shared
  // `_yds-layout.scss` rule touches, or sit next to ones that do, so they need
  // before/after evidence that nothing regressed: the CTA atom paints from
  // `--color-layout-border` (deliberately left alone) and the divider atom
  // from `--color-divider` (re-pointed).
  'button_link',
  'divider',
];

$storage = \Drupal::entityTypeManager()->getStorage('block_content');

// One representative source block per type, oldest first so re-runs pick the
// same one and before/after captures stay comparable.
$sources = [];
foreach ($blockTypes as $type) {
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

// The 70/30 entry needs `sidebar_filler`: a 70/30 section whose sidebar is
// empty collapses to a single column, so without it the capture would be
// indistinguishable from the One column one AND `.yds-layout__secondary` --
// which carries the column separator this work re-points -- would never
// render. See the same note in 1613-section-contrast-fixture.php.
$fixtures = [
  '1613 Block contrast - One column' => [
    'layout' => 'layout_onecol',
    'region' => 'content',
    'sidebar_filler' => FALSE,
  ],
  '1613 Block contrast - Two Column 70-30' => [
    'layout' => 'ys_layout_two_column',
    'region' => 'content',
    'sidebar_filler' => TRUE,
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

      if ($spec['sidebar_filler']) {
        $filler = BlockContent::create([
          'type' => 'text',
          'info' => sprintf('1613 sidebar filler - %s - %s', $type, $theme),
          'reusable' => FALSE,
          'field_text' => [
            'value' => '<p>Sidebar copy, so the 70/30 section keeps two '
            . 'columns and renders its separator.</p>',
            'format' => 'basic_html',
          ],
        ]);
        $filler->save();

        $section->appendComponent(new SectionComponent(
          \Drupal::service('uuid')->generate(),
          'sidebar',
          [
            'id' => 'inline_block:text',
            'label' => sprintf('Sidebar %s %s', $type, $theme),
            'label_display' => FALSE,
            'block_revision_id' => $filler->getRevisionId(),
            'block_serialized' => serialize($filler),
          ]
        ));
      }

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
