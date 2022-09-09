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
  $info['tokens']['yale']['news_landing_page_path'] = [
    'name' => t('News landing page'),
    'description' => t('The path alias for the news landing page.'),
  ];
  $info['tokens']['yale']['event_landing_page_path'] = [
    'name' => t('Events landing page'),
    'description' => t('The path alias for the events landing page.'),
  ];
  return $info;
}

/**
 * Implements hook_tokens().
 */
function ys_core_tokens($type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];
  if ($type == 'yale' && !empty($data['node'])) {
    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'news_landing_page_path':
          $config = \Drupal::config('ys_core.site');
          $replacements[$original] = $config->get('page.news');
          break;
        case 'event_landing_page_path':
          $config = \Drupal::config('ys_core.site');
          $replacements[$original] = $config->get('page.events');
          break;
      }
    }
  }
  return $replacements;
}
