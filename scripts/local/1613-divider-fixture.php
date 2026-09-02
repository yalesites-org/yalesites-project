<?php

/**
 * @file
 * Fixture proving each section layout draws its divider exactly once, and
 * only when the editor asked for it.
 *
 * Local audit fixture, not platform code -- run with:
 *   lando drush php:script scripts/local/1613-divider-fixture.php
 *
 * The other #1613 fixtures build every section with `divider => 0`, so they
 * cannot show anything about the Divider control. This one builds all four
 * section layouts TWICE, once with the toggle on and once off, so the control
 * is provable in both directions on one page.
 *
 * A 70/30 draws its separator as a border on `.yds-layout__secondary` rather
 * than as a `.yds-layout__divider` element, so it is measured differently
 * from the other two multi-column layouts -- the element is deliberately
 * suppressed for that layout or the same toggle would draw two lines
 * (component-library-twig#707).
 *
 * Expected per section, divider ON -> OFF:
 *  - one-column ............ 0 -> 0 elements (single region: nothing to divide)
 *  - seventy-thirty ........ 0 -> 0 elements, but the secondary column's
 *                            border-left goes from --thickness-divider to 0
 *                            (yalesites-project#1514)
 *  - fifty-fifty ........... 1 -> 0 elements
 *  - thirty-thirty-thirty .. 2 -> 0 elements
 */

use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;

$title = '1613 Divider once per layout';

// Region lists per layout, matching ys_layouts.layouts.yml. One column is
// core's layout_onecol.
$layouts = [
  'layout_onecol' => ['content'],
  'ys_layout_two_column' => ['content', 'sidebar'],
  'ys_layout_two_column_50_50' => ['content_primary', 'content_secondary'],
  'ys_layout_three_column_33_33_33' => [
    'content_primary',
    'content_secondary',
    'content_tertiary',
  ],
];

// Rebuild from scratch so re-runs are idempotent rather than additive.
$existing = \Drupal::entityTypeManager()
  ->getStorage('node')
  ->loadByProperties(['title' => $title]);
foreach ($existing as $node) {
  $node->delete();
}

$sections = [];

// Each layout twice: the toggle on, then the toggle off. A control that does
// nothing looks identical to a working one if you only ever render one state.
foreach ([1, 0] as $divider) {
  $state = $divider ? 'on' : 'off';

  foreach ($layouts as $layout => $regions) {
    // Theme five is a light section, so a divider drawn from the section
    // foreground is clearly visible against it in a screenshot.
    $section = new Section($layout, [
      'label' => sprintf('%s (divider %s)', $layout, $state),
      'theme' => 'five',
      'divider' => $divider,
    ]);

    foreach ($regions as $region) {
      $block = BlockContent::create([
        'type' => 'text',
        'info' => sprintf('1613 divider %s - %s - %s', $state, $layout, $region),
        'reusable' => FALSE,
        'field_text' => [
          // The FIRST region of each section gets a deliberately long body and
          // the rest get one line. Without that asymmetry every column is the
          // same height and a separator that stops at the short column looks
          // identical to one that spans the section -- which is exactly how a
          // short 70/30 separator went unnoticed.
          'value' => sprintf(
            '<h2>%s &mdash; divider %s</h2><p>Region %s. It contains '
            . '<a href="/">an inline link</a>.</p>%s',
            $layout,
            $state,
            $region,
            $region === $regions[0]
              ? str_repeat(
                '<p>Filler copy making the first column much taller than the '
                . 'others, so the separator has a tall section to span.</p>',
                6
              )
              : ''
          ),
          'format' => 'basic_html',
        ],
      ]);
      $block->save();

      $section->appendComponent(new SectionComponent(
        \Drupal::service('uuid')->generate(),
        $region,
        [
          'id' => 'inline_block:text',
          'label' => sprintf('Text %s %s %s', $state, $layout, $region),
          'label_display' => FALSE,
          'block_revision_id' => $block->getRevisionId(),
          'block_serialized' => serialize($block),
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

printf("%s => /node/%d\n", $title, $node->id());
