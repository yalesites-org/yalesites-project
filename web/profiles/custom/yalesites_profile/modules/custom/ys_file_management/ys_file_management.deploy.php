<?php

/**
 * @file
 * Drush deploy hooks for the ys_file_management module.
 */

/**
 * Converts legacy media links in rich text into direct file links (#835).
 */
function ys_file_management_deploy_9001() {
  $stats = \Drupal::service('ys_file_management.media_link_converter')->convertAllContent();

  return t('Converted @converted media link(s) to file links across @entities entit(y|ies). @skipped media link(s) were left unchanged (no downloadable file); @failed entit(y|ies) could not be saved.', [
    '@converted' => $stats['links_converted'],
    '@entities' => $stats['entities_updated'],
    '@skipped' => $stats['links_skipped'],
    '@failed' => $stats['entities_failed'],
  ]);
}
