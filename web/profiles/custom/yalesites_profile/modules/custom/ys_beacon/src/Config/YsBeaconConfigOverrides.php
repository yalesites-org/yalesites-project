<?php

namespace Drupal\ys_beacon\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Injects per-site Beacon values into platform-managed configuration.
 *
 * Two things are layered in at runtime rather than stored in synced config: the
 * Azure AI Search endpoint URL on the vector database connection config, and
 * safety-net toggles on the search index status and the Beacon chat setting.
 *
 * The endpoint URL is resolved from the Beacon search server's configured
 * connection URL (the field an admin edits, and the field
 * BeaconIndexManager pins the resolved endpoint onto once a site owns an
 * index); only when the server has no URL of its own does it fall back to the
 * platform default from a key entity backed by Pantheon secrets.
 *
 * The per-site index name and read-only flag are NOT overridden here: they are
 * persisted directly onto the real Search API config (the server's Azure
 * database name and the index's read_only flag) by
 * BeaconIndexManager::propagateConnection(), and kept through config import by
 * config-ignoring those keys. Persisting them - rather than overriding at
 * runtime - keeps the Search API admin UI truthful and points the chat query at
 * the configured collection.
 *
 * The index status is forced off at runtime whenever the chat widget is turned
 * off, the site is unauthorized, or no index name is configured yet, so
 * unconfigured or disabled sites never reach Azure. Status is the one index
 * value still derived at runtime because it depends on the platform
 * authorization and chat toggle, which can change without a settings-form save.
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
   * The search index config name is not a constant: its machine name is
   * configurable (ys_beacon.settings:search_index_id, defaulting to
   * "ys_beacon"), so it is resolved at runtime to support a second index
   * without code changes.
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

    if (in_array($index_config, $names, TRUE)) {
      // The chat toggle is the primary driver of index status: the settings
      // form enables or disables the index explicitly in sync with it. This
      // override only acts as a safety net, forcing the index off whenever
      // chat is disabled (including an unauthorized site) or no index name has
      // been configured yet.
      if (!$enable_chat || $index_name === '') {
        $overrides[$index_config]['status'] = FALSE;
      }
    }

    if (in_array(self::VDB_CONFIG, $names, TRUE)) {
      // The endpoint the admin configured on the Beacon search server wins; an
      // empty field falls back to the platform Pantheon-secret default. Once a
      // site creates (or adopts) an index the resolved endpoint is written back
      // onto that same server field by BeaconIndexManager::pinSearchUrl(), so a
      // later secret change only moves sites that have not created one yet
      // (yalesites-org/YaleSites-Internal#1440, #1448).
      $url = $this->configuredServerUrl($settings);
      if ($url === '') {
        $url = $this->getAzureSearchUrl(($settings['azure_search_url_key'] ?? '') ?: self::DEFAULT_URL_KEY);
      }
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
   *   TRUE when the name is the VDB settings, the Beacon settings, or a search
   *   index object.
   */
  protected function mayBeRelevant(string $name): bool {
    return $name === self::VDB_CONFIG
      || $name === self::SETTINGS_CONFIG
      || str_starts_with($name, 'search_api.index.');
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
   * The Azure endpoint URL configured on the Beacon search server.
   *
   * This is the value entered in the search server's Vector Database
   * Configuration form (backend_config.database_settings.url) and the field
   * BeaconIndexManager::pinSearchUrl() writes the resolved endpoint back onto
   * once a site owns an index. It is the site's authoritative endpoint: a
   * non-empty value is used as-is and only an empty one falls back to the
   * Pantheon-secret default. It is read from raw storage to see the persisted
   * value and to avoid re-entering the config factory while overrides resolve
   * (yalesites-org/YaleSites-Internal#1440, #1448).
   *
   * @param array $settings
   *   The raw ys_beacon settings.
   *
   * @return string
   *   The configured endpoint URL (scheme-normalized), or an empty string when
   *   the server has none.
   */
  protected function configuredServerUrl(array $settings): string {
    $server = $this->configStorage->read($this->serverConfigName($settings)) ?: [];
    $url = $server['backend_config']['database_settings']['url'] ?? '';
    return self::normalizeEndpoint((string) $url);
  }

  /**
   * The Search API server config object name backing the chatbot.
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
        $this->azureSearchUrl = self::normalizeEndpoint((string) ($key?->getKeyValue() ?? ''));
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
   * Normalizes an Azure AI Search endpoint into an absolute URL.
   *
   * The endpoint is read from a Pantheon-backed key that sometimes stores a
   * bare host (or a protocol-relative value) rather than a full URL. Guzzle
   * rejects a scheme-less request URI ("The scheme \"\" is not allowed by the
   * protocols request option"), which fails index provisioning and every Azure
   * request, so default a missing scheme to https - Azure AI Search is only
   * ever served over https.
   *
   * @param string $url
   *   The raw endpoint value from the key entity.
   *
   * @return string
   *   The endpoint with a scheme, or an empty string when none was configured.
   */
  public static function normalizeEndpoint(string $url): string {
    $url = trim($url);
    if ($url === '') {
      return '';
    }
    // Leave an already-absolute URL (any scheme) untouched.
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
      return $url;
    }
    // Bare host or protocol-relative "//host": Azure is https-only.
    return 'https://' . ltrim($url, '/');
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
      $this->indexConfigName($settings),
    ];
    if (in_array($name, $ours, TRUE)) {
      $metadata->addCacheTags(['config:ys_beacon.settings']);
    }
    // The endpoint is the server's configured URL, with the secret-backed key
    // as fallback, so the override must be invalidated when either changes: an
    // edited endpoint, or a newly synced key (for example from the
    // pantheon_secrets sync) that only takes effect after a full cache rebuild.
    if ($name === self::VDB_CONFIG) {
      $key_id = ($settings['azure_search_url_key'] ?? '') ?: self::DEFAULT_URL_KEY;
      $metadata->addCacheTags([
        'config:key.key.' . $key_id,
        'config:' . $this->serverConfigName($settings),
      ]);
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
