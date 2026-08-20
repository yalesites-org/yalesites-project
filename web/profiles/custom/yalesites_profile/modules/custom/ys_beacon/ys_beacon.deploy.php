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

/**
 * Queues PDF text extraction for documents that predate the trigger fix.
 *
 * Until issue #1580 extraction only ever fired when a media item's source file
 * changed, so no document uploaded and opted in through the normal editorial
 * flow has ever had its text extracted. On sites migrated from another
 * platform the whole media library predates Beacon and no editor action will
 * ever trigger it, so the backlog has to be swept once here rather than left
 * to the fixed trigger.
 *
 * This only queues: parsing PDFs is pure PHP, and the existing
 * ys_beacon_pdf_text_extraction worker already drains on cron, so the deploy
 * window stays short. The sandbox pages through the library because loading
 * every media item at once would not survive a large one. Sites where Beacon
 * is unauthorized or unconfigured are skipped outright, before the library is
 * enumerated at all; the per-item gate in _ys_beacon_queue_pdf_extraction()
 * still decides each document, so the rule cannot drift from the editorial
 * path.
 */
function ys_beacon_deploy_10002(array &$sandbox) {
  // Belt and braces: deploy:hook runs at full bootstrap, so the module file
  // holding the queueing helper is already loaded. Asking for it explicitly
  // costs nothing and keeps this hook honest about what it depends on.
  \Drupal::moduleHandler()->loadInclude('ys_beacon', 'module');

  // Beacon is authorized per site by a platform administrator and a site can
  // be left without an index name, so most sites receiving this deploy have it
  // switched off. _ys_beacon_queue_pdf_extraction() gates on exactly these two
  // conditions and would queue nothing anyway, but the enumeration that feeds
  // it - an entity query over the media library plus chunked entity loads -
  // still costs real deploy time on a large site to achieve nothing. Decide
  // once, up front. Deploy hooks run after config:import, so this reads the
  // site's post-import state, which is the state the queue would run against.
  if (!\Drupal::service('ys_beacon.authorization')->isAuthorized()
    || !\Drupal::config('ys_beacon.settings')->get('azure_index_name')) {
    $sandbox['#finished'] = 1;
    return t('Beacon is not enabled on this site; no PDF text extraction was queued.');
  }

  if (!isset($sandbox['ids'])) {
    $sandbox['ids'] = \Drupal::service('ys_beacon.pdf_text_indexer')->pendingMediaIds();
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['queued'] = 0;
  }
  if (!$sandbox['total']) {
    $sandbox['#finished'] = 1;
    return t('No PDF documents were waiting for text extraction.');
  }

  $storage = \Drupal::entityTypeManager()->getStorage('media');
  foreach ($storage->loadMultiple(array_splice($sandbox['ids'], 0, 50)) as $media) {
    if (_ys_beacon_queue_pdf_extraction($media)) {
      $sandbox['queued']++;
    }
  }

  if ($sandbox['ids']) {
    $sandbox['#finished'] = 1 - (count($sandbox['ids']) / $sandbox['total']);
    return NULL;
  }
  $sandbox['#finished'] = 1;
  return t('Queued @count PDF document(s) for text extraction; cron extracts them.', [
    '@count' => $sandbox['queued'],
  ]);
}

/**
 * Implements hook_deploy_NAME().
 *
 * Raises the stored model context window to Claude Sonnet 5's 1M tokens.
 *
 * Beacon now routes to Claude Sonnet 5, a 1M-context model, while every
 * existing site still holds Haiku's 200k. ys_beacon.settings is config-ignored
 * (ys_beacon*) and deliberately absent from config/sync, so the raised default
 * in config/install reaches new installs only - there is nothing in sync for
 * config:import to correct an existing site with. Hence a deploy hook.
 *
 * Only the untouched old default is raised. A site whose operator deliberately
 * set another window keeps it, because the routed model is a per-site Portkey
 * decision this code cannot observe.
 */
function ys_beacon_deploy_10003() {
  $config = \Drupal::configFactory()->getEditable('ys_beacon.settings');
  $window = $config->get('model_context_window');

  if ($window === NULL) {
    return t('Beacon settings are absent on this site; the model context window was not changed.');
  }
  if ((int) $window !== 200000) {
    return t('Beacon model context window left at its site-specific value (@value tokens).', [
      '@value' => (int) $window,
    ]);
  }

  $config->set('model_context_window', 1000000)->save();
  return t('Raised the Beacon model context window from 200000 to 1000000 tokens for Claude Sonnet 5.');
}
