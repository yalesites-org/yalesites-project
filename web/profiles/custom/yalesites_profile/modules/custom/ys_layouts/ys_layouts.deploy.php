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
