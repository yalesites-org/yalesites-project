<?php

/**
 * @file
 * Drush deploy hooks for ys_markdown module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Seeds the robots.txt textarea from core's scaffold file on existing sites.
 *
 * robotstxt_install() fills the textarea from the module's own bundled
 * robots.txt, which is older than core's, and config_ignore then keeps that
 * value instead of the synced seed. Deploy hooks run after config import, so
 * the module is installed on every path. A site that already edited its
 * textarea is left alone.
 */
function ys_markdown_deploy_10001(): string {
  $config = \Drupal::configFactory()->getEditable('robotstxt.settings');
  $current = trim((string) $config->get('content'));
  $bundled = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('robotstxt') . '/robots.txt';
  if ($current !== '' && $current !== trim((string) @file_get_contents($bundled))) {
    return (string) t('robots.txt content was edited on this site; left unchanged.');
  }
  $config->set('content', file_get_contents(DRUPAL_ROOT . '/core/assets/scaffold/files/robots.txt'))->save();
  return (string) t("robots.txt content seeded from core's scaffold file.");
}
