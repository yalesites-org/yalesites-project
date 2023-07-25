<?php

/**
 * @file
 * Drush deploy hooks for ys_layouts module.
 */

use Drupal\ys_layouts\UpdateExistingNodes;

/**
 * Updates existing nodes with new layouts.
 */
function ys_layouts_deploy_9001() {
  $updateExistingNodes = new UpdateExistingNodes();

//   // Adds new event meta block to existing events.
//   $updateExistingNodes->updateExistingEventMeta();

//   // Replaces old title and breadcrumb block with new page meta block.
  $updateExistingNodes->updateExistingPageMeta();

//   // Adds "Add section" to existing pages.
//   $updateExistingNodes->updateExistingPageLock();

//   // Replaces old title section with new post meta block.
//   $updateExistingNodes->updateExistingPostMeta();
}
