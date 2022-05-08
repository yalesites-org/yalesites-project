<?php

/**
 * @file
 * Contains test.module.
 */

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Implements hook_help().
 */
function test_help($route_name, RouteMatchInterface $route_match) {
  switch ($route_name) {
    // Main module help for the conditional_fields module.
    case 'help.page.conditional_fields':
          $output = '';
          $output .= '<h3>' . t('About') . '</h3>';
          $output .= '<p>' . t('Define dependencies between fields based on their states and values.') . '</p>';
          return $output;

    default:
  }
}
