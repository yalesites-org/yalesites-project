<?php

/**
 * @file
 * Drush deploy hooks for ys_layouts module.
 */

use Drupal\ys_layouts\Service\LayoutUpdaterLegacy;

/**
 * Updates existing nodes with new layouts.
 */
function ys_layouts_deploy_9001() {
  $updateExistingNodes = new LayoutUpdaterLegacy();
  $updateExistingNodes->updateExistingEventMeta();
  $updateExistingNodes->updateExistingPageMeta();
  $updateExistingNodes->updateExistingPageLock();
  $updateExistingNodes->updateExistingPostMeta();
}

/**
 * Updates events to disable adding new sections.
 */
function ys_layouts_deploy_9002() {
  $updateExistingNodes = new LayoutUpdaterLegacy();
  $updateExistingNodes->updateExistingEventsLock();
}

/**
 * Updates all content type section locks.
 */
function ys_layouts_deploy_9003() {
  \Drupal::service('ys_layouts.updater')->updateAllLocks();
}

/**
 * Updates all content spotlights to use new text formats.
 */
function ys_layouts_deploy_9004() {
  \Drupal::service('ys_layouts.updater')->updateTextFormats('content_spotlight', 'field_text');
  \Drupal::service('ys_layouts.updater')->updateTextFormats('content_spotlight_portrait', 'field_text');
}

/**
 * Opts existing Two column (70/30) sections into their divider.
 *
 * The 70/30 separator is now gated on the section's Divider setting, which
 * every existing section has stored as off because the control was inert on
 * that layout. Without this, those sections lose a separator they render
 * today. Covers the published revision and any pending draft. See
 * LayoutUpdater::enableSeventyThirtyDividers().
 */
function ys_layouts_deploy_9005() {
  $updated = \Drupal::service('ys_layouts.updater')->enableSeventyThirtyDividers();

  return \Drupal::translation()->formatPlural(
    $updated,
    'Enabled the 70/30 divider on 1 node revision.',
    'Enabled the 70/30 divider on @count node revisions.'
  );
}
