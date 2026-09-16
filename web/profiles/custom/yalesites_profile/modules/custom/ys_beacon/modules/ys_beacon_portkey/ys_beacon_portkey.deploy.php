<?php

/**
 * @file
 * Drush deploy hooks for ys_beacon_portkey module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Restores ys_beacon_portkey.settings when the config import has just deleted
 * it.
 *
 * The provider's settings are covered by the same ys_beacon* config_ignore
 * entry as the parent module and are deleted by the same mechanism: the module
 * is listed in synced core.extension, so on a site that does not have Beacon
 * yet config:import installs it, hook_install() seeds the settings, and the
 * changelist the importer recalculates afterwards sees that fresh object as a
 * delete. See ys_beacon.deploy.php for the full trace and for why the deploy
 * phase is the one that can make the write stick
 * (yalesites-org/YaleSites-Internal#1491).
 *
 * Without these settings the provider has no api_key pointer, so it cannot
 * load its key and Beacon cannot reach the LLM at all.
 *
 * Inert on every site whose settings survived, which is the overwhelming
 * majority.
 */
function ys_beacon_portkey_deploy_10001() {
  // Deploy hooks run from ys_beacon_portkey.deploy.php; the seeding helper
  // lives in the install file, which is not loaded for us. The helper owns the
  // "is this provider configured?" test and reports whether it wrote, so the
  // predicate is not restated here against a different view of the config -
  // \Drupal::config() applies settings.php overrides, getEditable() does not.
  \Drupal::moduleHandler()->loadInclude('ys_beacon_portkey', 'install');

  return _ys_beacon_portkey_seed_settings()
    ? t('Restored ys_beacon_portkey.settings deleted by the import.')
    : t('Portkey settings are present; nothing to restore.');
}
