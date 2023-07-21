<?php

/**
 * @file
 * Drush deploy hooks for ys_layouts module.
 */

use Drupal\ys_layouts\UpdateExistingNodes;

/**
 * Add event meta layout section to existing event nodes.
 */
function ys_layouts_deploy_9001() {
  $updateExistingNodes = new UpdateExistingNodes();
  $updateExistingNodes->updateExistingEventMeta();

  return t('Updated existing event nodes with meta block.');

}
