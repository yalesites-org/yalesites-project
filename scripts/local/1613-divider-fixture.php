<?php

/**
 * @file
 * Fixture proving each section layout draws its divider exactly once.
 *
 * Local audit fixture, not platform code -- run with:
 *   lando drush php:script scripts/local/1613-divider-fixture.php
 *
 * The other #1613 fixtures build every section with `divider => 0`, so they
 * cannot show anything about the opt-in Divider control. This one turns it ON
 * for all four section layouts at once.
 *
 * Expected rendered `.yds-layout__divider` count per section:
 *  - one-column ............ 0  (single region: nothing to divide)
 *  - seventy-thirty ........ 0  (already draws an always-on separator as the
 *                               border-left on .yds-layout__secondary, so the
 *                               opt-in element would be a SECOND line --
 *                               component-library-twig#707)
 *  - fifty-fifty ........... 1
 *  - thirty-thirty-thirty .. 2
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

foreach ($layouts as $layout => $regions) {
  // Theme five is a light section, so a divider drawn from the section
  // foreground is clearly visible against it in a screenshot.
  $section = new Section($layout, [
    'label' => $layout,
    'theme' => 'five',
    'divider' => 1,
  ]);

  foreach ($regions as $region) {
    $block = BlockContent::create([
      'type' => 'text',
      'info' => sprintf('1613 divider - %s - %s', $layout, $region),
      'reusable' => FALSE,
      'field_text' => [
        // The FIRST region of each section gets a deliberately long body and
        // the rest get one line. Without that asymmetry every column is the
        // same height and a separator that stops at the short column looks
        // identical to one that spans the section -- which is exactly how a
        // short 70/30 separator went unnoticed.
        'value' => sprintf(
          '<h2>%s</h2><p>Region %s. Divider is ON for this section, and it '
          . 'contains <a href="/">an inline link</a>.</p>%s',
          $layout,
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
        'label' => sprintf('Text %s %s', $layout, $region),
        'label_display' => FALSE,
        'block_revision_id' => $block->getRevisionId(),
        'block_serialized' => serialize($block),
      ]
    ));
  }

  $sections[] = $section;
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
