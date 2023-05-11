<?php

/**
 * @file
 * Builds custom tokens for YaleSites.
 */

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Implements hook_token_info().
 */
function ys_core_token_info() {
  $info = [];
  $info['types']['yale'] = [
    'name' => t('Yale Tokens'),
    'description' => t('Custom tokens for YaleSites.'),
  ];
  $info['tokens']['yale']['post_landing_page_path'] = [
    'name' => t('Posts landing page'),
    'description' => t('The path alias for the posts landing page.'),
  ];
  $info['tokens']['yale']['event_landing_page_path'] = [
    'name' => t('Events landing page'),
    'description' => t('The path alias for the events landing page.'),
  ];
  $info['tokens']['yale']['teaser_title_node_title'] = [
    'name' => t('Teaser title or node title'),
    'description' => t('Will use the teaser title of the node if set, and if not will fallback to the node title'),
  ];
  return $info;
}

/**
 * Implements hook_tokens().
 */
function ys_core_tokens($type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {

  /* Note: If node data is needed, add a check (!empty($data['node'])
   * inside a specific case statement below.
   */

  $replacements = [];
  if ($type == 'yale') {
    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'post_landing_page_path':
          // Landing page path is a site setting; fallback to '/posts'.
          $config = \Drupal::config('ys_core.site');
          $replacements[$original] = $config->get('page.posts') ?: '/posts';
          break;

        case 'event_landing_page_path':
          // Landing page path is a site setting; fallback to '/event'.
          $config = \Drupal::config('ys_core.site');
          $replacements[$original] = $config->get('page.events') ?: '/events';
          break;

        case 'teaser_title_node_title':
          if (!empty($data['node'])) {
            $nodeTitle = $data['node']->getTitle();
            $teaserTitle = NULL;
            if ($data['node']->field_teaser_title) {
              $teaserTitle = ($data['node']->field_teaser_title->first()) ? $data['node']->field_teaser_title->first()->getString() : NULL;
            }

            $replacements[$original] = $teaserTitle ?: $nodeTitle;
          }
          break;
      }
    }
  }
  return $replacements;
}
