<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrates a site from the legacy ai_engine chatbot to Beacon.
 *
 * The platform is retiring ai_engine/ys_ai in favour of ys_beacon, which ships
 * and is enabled through config. The editor-facing chat settings are already
 * copied when the module is installed (ys_beacon_install()). What cannot be
 * copied at install time is the live state: whether the chatbot was actually
 * turned on, and the per-site Azure AI Search index that backs it. Provisioning
 * that index needs a reachable Azure connection whose key is created by the
 * pantheon_secrets sync only after the module is installed, and the search
 * index entity itself is imported from profile config after module install - so
 * neither is guaranteed to be ready while ys_beacon_install() runs.
 *
 * This service performs that cutover idempotently so it can run from cron until
 * it succeeds: when the legacy chat was enabled it turns Beacon chat on,
 * provisions and queues the index, then disables the legacy ai_engine chat
 * widget and embedding pipeline so visitors only ever see one assistant. Sites
 * that never enabled the legacy chatbot - the large majority - are left off.
 */
class BeaconMigration {

  /**
   * Beacon settings config name.
   */
  protected const SETTINGS = 'ys_beacon.settings';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected BeaconIndexManager $indexManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Runs the legacy-to-Beacon cutover for this site when one is pending.
   *
   * Safe and cheap to call repeatedly (cron, install, update): it returns early
   * on the common paths - nothing to migrate, or already migrated - and only
   * writes config when state actually changes. A failed index provisioning
   * leaves the site in a safe holding state (Beacon chat marked on but its
   * index forced off by the config override, the legacy widget still serving)
   * and is retried on the next call.
   */
  public function migrate(): void {
    $settings = $this->configFactory->getEditable(self::SETTINGS);
    // Settings object not created yet (module mid-install): nothing to do.
    if ($settings->isNew()) {
      return;
    }

    $legacy_chat_on = $this->legacyChatOn();
    $beacon_enabled = (bool) $settings->get('enable_chat');

    // Common no-op: the legacy chat was never on and Beacon is not enabled, so
    // there is nothing to carry over. The large majority of sites take this
    // path on every cron run.
    if (!$legacy_chat_on && !$beacon_enabled) {
      return;
    }

    // Cutover already complete: Beacon is on with a provisioned index and the
    // legacy widget is off.
    if ($beacon_enabled && $settings->get('azure_index_name') && !$legacy_chat_on) {
      return;
    }

    // A cutover is pending. Record the intent first: Beacon chat on, and the AI
    // metadata fields on (they are always on while the chatbot is on). Setting
    // enable_chat before provisioning matches the settings form: the config
    // override only lets the index come online once enable_chat is true.
    if (!$beacon_enabled || !$settings->get('enable_metadata_fields')) {
      $settings
        ->set('enable_chat', TRUE)
        ->set('enable_metadata_fields', TRUE)
        ->save();
    }

    // Ensure the per-site Azure index exists. provision() persists the index
    // name and rebuilds Search API tracking; on a connection failure it throws,
    // and we defer to the next run rather than tearing the legacy widget down
    // before Beacon can actually answer.
    if (!$settings->get('azure_index_name')) {
      try {
        $this->indexManager->provision();
      }
      catch (\RuntimeException $e) {
        $this->logger->notice('Beacon migration: index provisioning deferred (@message). The legacy chat stays active until Beacon is ready; will retry.', ['@message' => $e->getMessage()]);
        return;
      }
    }

    // The search index entity is imported from profile config after the module
    // is installed, so it may not exist on the very first run. Defer the rest
    // until it does; the index name is already saved, so the next run resumes
    // here without re-provisioning.
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->searchIndexId());
    if (!$index) {
      return;
    }
    if (!$index->status()) {
      $index->setStatus(TRUE)->save();
    }
    $index->reindex();

    // Beacon is live and indexing. Retire the legacy ai_engine widgets so the
    // two assistants never run together.
    $this->disableLegacy();

    $this->logger->notice('Beacon migration complete: chat enabled, index %name queued for indexing, legacy ai_engine chat and embedding disabled.', ['%name' => $settings->get('azure_index_name')]);
  }

  /**
   * Whether the legacy ai_engine chat widget is installed and enabled.
   *
   * Expressed through injected dependencies so the cutover can be unit tested.
   * This is the same condition as the ys_beacon_legacy_chat_active() render
   * guard in ys_beacon.module (both read ai_engine_chat.settings:enable); keep
   * them in sync if that definition ever changes.
   *
   * @return bool
   *   TRUE when ai_engine_chat is installed and its chat widget is enabled.
   */
  protected function legacyChatOn(): bool {
    return $this->moduleHandler->moduleExists('ai_engine_chat')
      && (bool) $this->configFactory->get('ai_engine_chat.settings')->get('enable');
  }

  /**
   * Turns off the legacy ai_engine chat widget and embedding pipeline.
   *
   * Only writes when a flag is actually on, so repeat calls are no-ops. Both
   * config objects are config_ignored, so these runtime changes survive future
   * config imports.
   */
  protected function disableLegacy(): void {
    if ($this->moduleHandler->moduleExists('ai_engine_chat')) {
      $chat = $this->configFactory->getEditable('ai_engine_chat.settings');
      if ($chat->get('enable') || $chat->get('floating_button')) {
        $chat->set('enable', FALSE)->set('floating_button', FALSE)->save();
      }
    }
    if ($this->moduleHandler->moduleExists('ai_engine_embedding')) {
      $embedding = $this->configFactory->getEditable('ai_engine_embedding.settings');
      if ($embedding->get('enable')) {
        $embedding->set('enable', FALSE)->save();
      }
    }
  }

  /**
   * The Search API index machine name backing the chatbot.
   *
   * @return string
   *   The configured index machine name, defaulting to "ys_beacon".
   */
  protected function searchIndexId(): string {
    return $this->configFactory->get(self::SETTINGS)->get('search_index_id') ?: 'ys_beacon';
  }

}
