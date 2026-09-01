<?php

/**
 * @file
 * Builds the fixture the #1614 functional-element contrast audit measures.
 *
 * Local audit fixture, not platform code -- run with:
 *   lando drush php:script scripts/local/1614-functional-contrast-fixture.php
 *
 * #1613's fixture proved the section backgrounds paint and audited the blocks
 * it changed. It deliberately skipped accordion, link_grid and
 * wrapped_text_callout, which are #1614's subject. This builds the same shape
 * for those three.
 *
 * The difference from #1613's fixture is the COLOR DIAL sweep. #1614's AC #4
 * asks for the composited result when a block carries its own
 * `field_style_color` at the same time as the section carries a background, so
 * every (dial x section theme) pairing is placed rather than only the default
 * dial. The dial values come from ys_themes.component_overrides.yml:
 * accordion offers `default` plus one-six, the other two offer one-six only.
 *
 * One node per block type, each holding (dial x section theme) sections, so a
 * single page load per global theme measures every pairing for that block.
 * That is 3 pages x 7 global themes = 21 loads instead of 114 x 7.
 *
 * Read the resolved colors out with scripts/local/1614-measure-rendered.js.
 */

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;

$sectionThemes = ['one', 'two', 'three', 'four', 'five', 'six'];

/**
 * The block types this fixture renders, with the dial values each offers.
 *
 * Transcribed from ys_themes.component_overrides.yml. Accordion is the only
 * one of the three whose picker offers "Default - No Color", and that value is
 * load-bearing: `_yds-accordion.scss` gates its whole themed treatment on
 * `:not([data-component-theme='default'])`, so the default and the dialled
 * accordion are two different components as far as contrast is concerned.
 */
$blockTypes = [
  'accordion' => ['default', 'one', 'two', 'three', 'four', 'five', 'six'],
  'link_grid' => ['one', 'two', 'three', 'four', 'five', 'six'],
  'wrapped_text_callout' => ['one', 'two', 'three', 'four', 'five', 'six'],
];

$storage = \Drupal::entityTypeManager()->getStorage('block_content');

foreach ($blockTypes as $type => $dials) {
  // Oldest block of the type, so re-runs clone the same one and successive
  // measurements stay comparable. Cloning rather than constructing keeps the
  // field values realistic -- link_grid in particular needs populated link
  // lists before it renders any column headings or links to measure.
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

  $source = $storage->load(reset($ids));
  $title = sprintf('1614 Functional contrast - %s', $type);

  $existing = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['title' => $title]);
  foreach ($existing as $node) {
    $node->delete();
  }

  $sections = [];

  foreach ($dials as $dial) {
    foreach ($sectionThemes as $sectionTheme) {
      $clone = $source->createDuplicate();
      $clone->set('info', sprintf('1614 %s - dial %s - section %s', $type, $dial, $sectionTheme));
      $clone->set('reusable', FALSE);
      $clone->set('field_style_color', $dial);

      // The block heading is the element AC #2 names, so it has to be present
      // on every clone -- the source block may not have one.
      if ($clone->hasField('field_heading')) {
        $clone->set('field_heading', sprintf('Block heading %s %s', $dial, $sectionTheme));
      }

      // AC #1 lists body text and links as functional elements, and the
      // callout heading is a separate element from the block heading. The
      // source callout carries none of the three reliably, and an element that
      // does not render is measured as neither pass nor fail -- a silent hole
      // in the audit rather than a result. Supplied here so every cell has a
      // value.
      if ($clone->hasField('field_callout_text')) {
        $clone->set('field_callout_text', [
          'value' => '<h2>Callout heading</h2><p>Callout body copy with '
          . '<a href="https://example.com">a link</a> in it.</p>',
          'format' => 'basic_html',
        ]);
      }
      if ($clone->hasField('field_text')) {
        $clone->set('field_text', [
          'value' => '<p>Body copy with <a href="https://example.com">a link</a> in it.</p>',
          'format' => 'basic_html',
        ]);
      }

      // Link grid column headings come from the referenced link_list
      // paragraphs, which `createDuplicate()` leaves SHARED with the source
      // block. Writing a heading onto them directly would edit the original
      // content, so each paragraph is duplicated first.
      if ($clone->hasField('field_link_lists')) {
        $lists = [];
        foreach ($clone->get('field_link_lists')->referencedEntities() as $index => $paragraph) {
          $copy = $paragraph->createDuplicate();
          $copy->set('field_list_heading', sprintf('Column heading %d', $index + 1));
          $copy->save();
          $lists[] = $copy;
        }
        $clone->set('field_link_lists', $lists);
      }

      $clone->save();

      $section = new Section('layout_onecol', [
        'label' => sprintf('%s / dial %s / section %s', $type, $dial, $sectionTheme),
        'theme' => $sectionTheme,
        'divider' => 0,
      ]);

      $section->appendComponent(new SectionComponent(
        \Drupal::service('uuid')->generate(),
        'content',
        [
          'id' => 'inline_block:' . $type,
          'label' => sprintf('%s %s %s', $type, $dial, $sectionTheme),
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
