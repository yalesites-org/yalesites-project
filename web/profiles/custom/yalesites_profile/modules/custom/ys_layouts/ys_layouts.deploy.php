<?php

/**
 * @file
 * Drush deploy hooks for ys_layouts module.
 */

use Drupal\ys_layouts\UpdateEventMeta;

/**
 * Add event meta layout section to existing event nodes.
 */
function ys_layouts_deploy_9001() {
  $updateEventMeta = new UpdateEventMeta();
  $updateEventMeta->updateExistingEventMeta();

  return t('Updated existing event nodes with meta block.');

}
