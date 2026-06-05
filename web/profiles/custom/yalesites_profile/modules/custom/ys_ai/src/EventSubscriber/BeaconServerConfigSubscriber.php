<?php

namespace Drupal\ys_ai\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\ys_ai\Config\BeaconSearchConfigOverride;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Guarantees the Beacon server has a string index name on first import.
 *
 * The Beacon server's database_name (and the Azure URL) are config-ignored so a
 * value entered for an environment is not overwritten by a later config import.
 * The trade-off is that config_ignore "simple" mode strips an ignored sub-key
 * on a CREATE operation (no existing active value to preserve it against), so
 * on a first-time import search_api.server.beacon is stored without a
 * database_name key at all.
 *
 * ai_search's NewServerEventSubscriber reacts to the same save by reading the
 * server's raw (non-overridden) data and calling
 * AzureAiSearchProvider::getCollections() with that index name. With the key
 * missing it passes NULL to a non-nullable string parameter, throwing a
 * TypeError that the surrounding catch (\Exception) does not catch (a TypeError
 * is an \Error), which aborts the import.
 *
 * This subscriber runs before ai_search's and defaults a missing index name to
 * an empty string on the in-memory config, mirroring the value the synced
 * config carries when the key is not ignored. The empty string satisfies the
 * type hint so the import succeeds; BeaconSearchConfigOverride still supplies
 * the real per-environment index name at read time. The value is not persisted,
 * the stored config stays free of an environment-specific name.
 */
class BeaconServerConfigSubscriber implements EventSubscriberInterface {

  /**
   * Defaults a missing Beacon index name to an empty string before save.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config save event.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== BeaconSearchConfigOverride::SERVER_CONFIG_NAME) {
      return;
    }

    $backend_config = $config->get('backend_config');
    if (!is_array($backend_config)) {
      return;
    }

    if (!isset($backend_config['database_settings']['database_name'])) {
      $backend_config['database_settings']['database_name'] = '';
      $config->set('backend_config', $backend_config);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run before ai_search's NewServerEventSubscriber (default priority 0).
    return [
      ConfigEvents::SAVE => ['onConfigSave', 256],
    ];
  }

}
