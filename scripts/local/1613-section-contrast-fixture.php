<?php

/**
 * @file
 * Builds the two fixture nodes the #1613 section-background audit renders.
 *
 * Local audit fixture, not platform code -- run with:
 *   lando drush php:script scripts/local/1613-section-contrast-fixture.php
 *
 * Creates (or rebuilds) one node per section type, each carrying one section
 * per section-theme option so a single page load shows every background at
 * once. That is what makes the prerequisite check affordable: 2 pages x 7
 * global themes = 14 captures instead of 2 x 6 x 7 = 84.
 *
 * Each section holds a Text block whose body has a heading, body copy and a
 * link, so one screenshot exercises the three foreground roles
 * (--color-heading, --color-layout-content, --color-link-base) that
 * _yds-layout.scss publishes.
 */

use Drupal\block_content\Entity\BlockContent;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;

// The section-theme options YSLayoutOptions offers, minus 'default'.
$themes = ['one', 'two', 'three', 'four', 'five', 'six'];

// 70/30 gets a block in BOTH regions: with the sidebar empty the section
// collapses to a single column and the screenshot is indistinguishable from
// the One column one, which would make the 70/30 evidence worthless.
$fixtures = [
  '1613 One column section backgrounds' => [
    'layout' => 'layout_onecol',
    'regions' => ['content'],
  ],
  '1613 Two Column 70-30 section backgrounds' => [
    'layout' => 'ys_layout_two_column',
    'regions' => ['content', 'sidebar'],
  ],
];

foreach ($fixtures as $title => $spec) {
  // Rebuild from scratch so re-runs are idempotent rather than additive.
  $existing = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['title' => $title]);
  foreach ($existing as $node) {
    $node->delete();
  }

  $sections = [];

  foreach ($themes as $theme) {
    $section = new Section($spec['layout'], [
      'label' => sprintf('Theme %s', $theme),
      'theme' => $theme,
      'divider' => 0,
    ]);

    foreach ($spec['regions'] as $region) {
      $block = BlockContent::create([
        'type' => 'text',
        'info' => sprintf('%s - theme %s - %s', $title, $theme, $region),
        'reusable' => FALSE,
        'field_text' => [
          'value' => sprintf(
            '<h2>Section theme %s heading (%s)</h2><p>Body copy on section '
            . 'theme %s. This sentence exists to show the inherited section '
            . 'foreground at normal text size, and it contains '
            . '<a href="/">an inline link</a> so the link color is visible '
            . 'too.</p>',
            $theme,
            $region,
            $theme
          ),
          // basic_html, NOT heading_html: heading_html allows only
          // '<em> <strong> <p>', so it strips the <h2> and the <a> out of
          // the markup above -- which meant this fixture exercised only
          // --color-layout-content and never --color-heading or
          // --color-link-base, despite claiming all three.
          'format' => 'basic_html',
        ],
      ]);
      $block->save();

      $section->appendComponent(new SectionComponent(
        \Drupal::service('uuid')->generate(),
        $region,
        [
          'id' => 'inline_block:text',
          'label' => sprintf('Text %s %s', $theme, $region),
          'label_display' => FALSE,
          'block_revision_id' => $block->getRevisionId(),
          'block_serialized' => serialize($block),
        ]
      ));
    }

    $sections[] = $section;
  }

  // 'page' is under content moderation, so 'status' alone leaves the node in
  // 'draft' and anonymous requests 403. moderation_state is what actually
  // decides published-ness here; setting it is what makes the fixture
  // reachable without a session.
  $node = Node::create([
    'type' => 'page',
    'title' => $title,
    'moderation_state' => 'published',
    'uid' => 1,
  ]);
  $node->set('layout_builder__layout', $sections);
  $node->save();

  printf("%s => /node/%d\n", $title, $node->id());
}
