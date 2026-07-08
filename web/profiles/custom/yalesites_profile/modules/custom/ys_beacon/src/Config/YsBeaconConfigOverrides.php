<?php

namespace Drupal\ys_beacon\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Injects per-site Beacon values into platform-managed configuration.
 *
 * The search server and vector database connection configuration are shipped
 * in the profile config sync directory so they deploy identically to every
 * site. The per-site values (the Azure AI Search index name and endpoint URL)
 * live outside of synced config: the index name in ys_beacon.settings (which
 * is config_ignored) and the endpoint URL in a key entity backed by Pantheon
 * secrets. This override layers them in at runtime, so config imports never
 * overwrite them. Search API explicitly supports overrides on server
 * backend_config and index status.
 *
 * The index is also disabled at runtime whenever the chat widget is turned
 * off or a site has not configured its index name yet, so unconfigured or
 * disabled sites never attempt to reach Azure.
 *
 * Finally, when a platform admin has not authorized Beacon for a site, this
 * override forces the chat off (ys_beacon.settings:enable_chat) so the site
 * behaves exactly as if the site admin had disabled it: the widget, APIs, and
 * index all stand down. The authorization flag is read from raw storage, so
 * this never re-enters the config factory while overrides resolve.
 */
class YsBeaconConfigOverrides implements ConfigFactoryOverrideInterface {

  /**
   * The vector-database connection config this class overrides.
   *
   * The search server and index config names are not constants: their machine
   * names are configurable (ys_beacon.settings:search_server_id /
   * search_index_id, defaulting to "ys_beacon"), so they are resolved at
   * runtime to support a second index without code changes.
   */
  protected const VDB_CONFIG = 'ai_vdb_provider_azure_ai_search.settings';

  /**
   * The Beacon settings config whose chat toggle this class overrides.
   */
  protected const SETTINGS_CONFIG = 'ys_beacon.settings';

  /**
   * Default key entity id holding the Azure AI Search endpoint URL.
   *
   * The endpoint key id is deliberately left blank in per-site config so it is
   * never stored, so a blank pointer must resolve to this platform-wide key.
   */
  public const DEFAULT_URL_KEY = 'azure_ai_search_url';

  /**
   * Static cache of the Azure Search endpoint URL from the key module.
   *
   * @var string|null
   */
  protected ?string $azureSearchUrl = NULL;

  /**
   * Guards against re-entrant key loading.
   *
   * @var bool
   */
  protected bool $loadingKey = FALSE;

  public function __construct(
    protected StorageInterface $configStorage,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    // Cheap early-out without reading settings: skip anything that cannot be
    // one of our config objects.
    if (!array_filter($names, [$this, 'mayBeRelevant'])) {
      return $overrides;
    }

    $settings = $this->getSettings();
    $server_config = $this->serverConfigName($settings);
    $index_config = $this->indexConfigName($settings);
    $index_name = $settings['azure_index_name'] ?? '';

    // A site is only "chat enabled" when its own toggle is on AND a platform
    // admin has authorized Beacon for it. Folding authorization in here means
    // an unauthorized site behaves exactly as if chat were turned off, without
    // touching the site admin's saved enable_chat value.
    $authorized = !empty($settings['platform_authorized']);
    $enable_chat = ($settings['enable_chat'] ?? FALSE) && $authorized;

    // When Beacon is not authorized, force the chat off for every consumer that
    // reads enable_chat through the config factory (the widget attach, the
    // conversation API, the floating button). The saved value is untouched, so
    // re-authorizing restores it.
    if (!$authorized && in_array(self::SETTINGS_CONFIG, $names, TRUE)) {
      $overrides[self::SETTINGS_CONFIG] = ['enable_chat' => FALSE];
    }

    if (in_array($server_config, $names, TRUE) && $index_name !== '') {
      $overrides[$server_config] = [
        'backend_config' => [
          'database_settings' => [
            'database_name' => $index_name,
          ],
        ],
      ];
    }

    if (in_array($index_config, $names, TRUE)) {
      // The chat toggle is the primary driver of index status: the settings
      // form enables or disables the index explicitly in sync with it. This
      // override only acts as a safety net, forcing the index off whenever
      // chat is disabled (including an unauthorized site) or no index name has
      // been configured yet.
      if (!$enable_chat || $index_name === '') {
        $overrides[$index_config] = ['status' => FALSE];
      }
    }

    if (in_array(self::VDB_CONFIG, $names, TRUE)) {
      $url = $this->getAzureSearchUrl(($settings['azure_search_url_key'] ?? '') ?: self::DEFAULT_URL_KEY);
      if ($url !== '') {
        $overrides[self::VDB_CONFIG] = ['url' => $url];
      }
    }

    return $overrides;
  }

  /**
   * Whether a config name could be one this override touches.
   *
   * A prefix/equality test that needs no settings read, so unrelated config
   * loads stay cheap.
   *
   * @param string $name
   *   The config object name.
   *
   * @return bool
   *   TRUE when the name is the VDB settings or a search server/index object.
   */
  protected function mayBeRelevant(string $name): bool {
    return $name === self::VDB_CONFIG
      || $name === self::SETTINGS_CONFIG
      || str_starts_with($name, 'search_api.server.')
      || str_starts_with($name, 'search_api.index.');
  }

  /**
   * The search server config object name backing the chatbot.
   *
   * @param array $settings
   *   The raw ys_beacon settings.
   *
   * @return string
   *   The config object name.
   */
  protected function serverConfigName(array $settings): string {
    return 'search_api.server.' . (($settings['search_server_id'] ?? '') ?: 'ys_beacon');
  }

  /**
   * The search index config object name backing the chatbot.
   *
   * @param array $settings
   *   The raw ys_beacon settings.
   *
   * @return string
   *   The config object name.
   */
  protected function indexConfigName(array $settings): string {
    return 'search_api.index.' . (($settings['search_index_id'] ?? '') ?: 'ys_beacon');
  }

  /**
   * Reads the raw ys_beacon settings from active config storage.
   *
   * The raw storage is used instead of the config factory to avoid circular
   * dependencies while overrides are being resolved. The read is deliberately
   * not cached on this service: the cached storage layer already makes it
   * cheap, it only happens for three config names, and caching it would
   * serve stale overrides when ys_beacon settings are saved and then used in
   * the same request (the index provisioning flow does exactly that).
   *
   * @return array
   *   The raw settings, or an empty array before the module is installed.
   */
  protected function getSettings(): array {
    return $this->configStorage->read('ys_beacon.settings') ?: [];
  }

  /**
   * Resolves the Azure AI Search endpoint URL from a key entity.
   *
   * @param string $key_id
   *   The key entity id holding the endpoint URL.
   *
   * @return string
   *   The endpoint URL, or an empty string when unavailable.
   */
  protected function getAzureSearchUrl(string $key_id): string {
    if ($this->azureSearchUrl !== NULL) {
      return $this->azureSearchUrl;
    }
    if ($key_id === '' || $this->loadingKey) {
      return '';
    }

    $this->loadingKey = TRUE;
    try {
      // The key repository is resolved lazily on purpose: this class is a
      // config factory override instantiated during early bootstrap, and
      // injecting key.repository (entity system, which itself loads config)
      // would create a circular service dependency.
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      if (\Drupal::hasService('key.repository')) {
        // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
        $key = \Drupal::service('key.repository')->getKey($key_id);
        $this->azureSearchUrl = trim((string) ($key?->getKeyValue() ?? ''));
      }
      else {
        $this->azureSearchUrl = '';
      }
    }
    catch (\Throwable $e) {
      // Key module not installed yet, key missing, or the secrets backend is
      // unavailable. Leave the shipped configuration untouched.
      $this->azureSearchUrl = '';
    }
    finally {
      $this->loadingKey = FALSE;
    }

    return $this->azureSearchUrl;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'ys_beacon';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    $metadata = new CacheableMetadata();
    if (!$this->mayBeRelevant($name)) {
      return $metadata;
    }

    $settings = $this->getSettings();
    $ours = [
      self::VDB_CONFIG,
      self::SETTINGS_CONFIG,
      $this->serverConfigName($settings),
      $this->indexConfigName($settings),
    ];
    if (in_array($name, $ours, TRUE)) {
      $metadata->addCacheTags(['config:ys_beacon.settings']);
    }
    // The injected endpoint URL is read from the key entity, so the override
    // must be invalidated when that key is created or changed (for example by
    // the pantheon_secrets sync). Without this, a newly synced key only takes
    // effect after a full cache rebuild.
    if ($name === self::VDB_CONFIG) {
      $key_id = ($settings['azure_search_url_key'] ?? '') ?: self::DEFAULT_URL_KEY;
      $metadata->addCacheTags(['config:key.key.' . $key_id]);
    }
    return $metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
