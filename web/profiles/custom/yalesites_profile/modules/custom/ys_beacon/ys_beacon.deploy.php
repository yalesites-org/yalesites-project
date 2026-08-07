<?php

/**
 * @file
 * Drush deploy hooks for ys_beacon module.
 */

/**
 * Implements hook_deploy_NAME().
 *
 * Restores ys_beacon.settings when the config import has just deleted it.
 *
 * ys_beacon.settings is config-ignored (ys_beacon*) and deliberately absent
 * from config/sync, so on a site that does not have Beacon yet nothing in sync
 * can create it. ys_beacon is listed in synced core.extension, so config:import
 * is what installs the module there, and _ys_beacon_seed_settings() creates the
 * object from the shipped file during hook_install(). The import then deletes
 * it again: once the extension phase has installed the module,
 * ConfigImporter::processConfigurations() recalculates the changelist through
 * $this->storageComparer->reset() (ConfigImporter.php:656), and that
 * recalculation compares active storage - which now holds the seeded object -
 * against the already-transformed sync storage, which does not. That reads as a
 * delete, and config_ignore cannot veto it, because
 * ImportStorageTransformer::transform() returns the cached import storage while
 * the importer holds its lock. The ys_beacon* ignore is therefore only ever
 * evaluated before the module exists, when there is nothing to protect
 * (yalesites-org/YaleSites-Internal#1491).
 *
 * The deploy phase is the one that can make the write stick: `drush deploy`
 * runs deploy hooks after config:import, so no changelist is recalculated
 * afterwards. A hook_post_update_NAME() would not do, on two counts - it runs
 * inside `drush updatedb`, before the import, and
 * UpdateRegistry::onConfigSave() registers a newly installed extension's
 * post_update functions as already invoked, so it would never fire on the very
 * sites that need it. Drush builds the deploy registry ad hoc in
 * DeployHookCommands::getRegistry() rather than as a container service, so it
 * has no such subscriber and a new module's deploy hooks stay pending.
 *
 * This complements rather than replaces ys_core_update_10017(), which installs
 * the Beacon stack during the updatedb phase so the import never installs it at
 * all. That hook is one-shot: it cannot run on a site whose ys_core schema has
 * already passed 10017 but which does not have Beacon installed, and that is
 * exactly the site this restores.
 *
 * Writes nothing on a site whose settings are already complete, which is the
 * overwhelming majority.
 */
function ys_beacon_deploy_10001() {
  // Deploy hooks run from ys_beacon.deploy.php; the provisioning helpers live
  // in the install file, which is not loaded for us.
  \Drupal::moduleHandler()->loadInclude('ys_beacon', 'install');

  // Only a wholly absent object is the state hook_install() would have left,
  // so only then is the legacy ai_engine overlay re-applied. A settings object
  // that exists but is missing keys was recreated by a runtime write after the
  // import deleted it; re-running the overlay there would stamp legacy values
  // over an editor's own, so it only gets the missing defaults filled in.
  if (\Drupal::config('ys_beacon.settings')->isNew()) {
    _ys_beacon_provision_settings();
    return t('Restored ys_beacon.settings deleted by the configuration import.');
  }

  return _ys_beacon_seed_settings()
    ? t('Filled in Beacon settings defaults missing from an incomplete configuration.')
    : t('Beacon settings are complete; nothing to restore.');
}
