<?php

namespace Drupal\ys_ai\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Supplies per-site values for the Beacon Search (Azure AI Search) config.
 *
 * The Azure AI Search endpoint URL is provisioned per site as a Pantheon
 * Secret, exposed via the Drupal Key entity `azure_ai_search_url`. The Azure
 * index name is derived from the Pantheon site and environment so each
 * Pantheon environment (including multidevs) lands in its own isolated index
 * without manual configuration.
 */
class BeaconSearchConfigOverride implements ConfigFactoryOverrideInterface {

  const URL_CONFIG_NAME = 'ai_vdb_provider_azure_ai_search.settings';
  const SERVER_CONFIG_NAME = 'search_api.server.beacon';
  const URL_KEY_ID = 'azure_ai_search_url';

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The active config storage, used to read stored values without overrides.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * Constructs a BeaconSearchConfigOverride.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The active config storage.
   */
  public function __construct(KeyRepositoryInterface $key_repository, StorageInterface $config_storage) {
    $this->keyRepository = $key_repository;
    $this->configStorage = $config_storage;
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];

    if (in_array(self::URL_CONFIG_NAME, $names, TRUE)) {
      $url = $this->getAzureUrl();
      if ($url !== '') {
        $overrides[self::URL_CONFIG_NAME] = ['url' => $url];
      }
    }

    if (in_array(self::SERVER_CONFIG_NAME, $names, TRUE)) {
      $stored = $this->configStorage->read(self::SERVER_CONFIG_NAME);
      $storedName = $stored['backend_config']['database_settings']['database_name'] ?? '';
      if ($storedName === '') {
        $database_name = $this->getDatabaseName();
        if ($database_name !== '') {
          $overrides[self::SERVER_CONFIG_NAME] = [
            'backend_config' => [
              'database_settings' => [
                'database_name' => $database_name,
              ],
            ],
          ];
        }
      }
    }

    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'ys_ai_beacon_search';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * Resolves the Azure AI Search URL from the configured Key entity.
   *
   * @return string
   *   The URL, or an empty string when no Key or value is available.
   */
  protected function getAzureUrl() {
    $key = $this->keyRepository->getKey(self::URL_KEY_ID);
    if ($key === NULL) {
      return '';
    }
    $value = $key->getKeyValue();
    return is_string($value) ? trim($value) : '';
  }

  /**
   * Derives the Azure index name from the current environment.
   *
   * Checks Pantheon first, then Lando, then DDEV. Returns an empty string
   * when none of those environments are detected.
   *
   * @return string
   *   The derived database (index) name, or an empty string when the
   *   environment cannot be identified.
   */
  protected function getDatabaseName() {
    $site = $this->readEnv('PANTHEON_SITE_NAME');
    $env = $this->readEnv('PANTHEON_ENVIRONMENT');
    if ($site !== '' && $env !== '') {
      return $site . '-' . $env;
    }

    $lando = $this->readEnv('LANDO_APP_NAME');
    if ($lando !== '') {
      return $lando . '-local';
    }

    $ddev = $this->readEnv('DDEV_SITENAME');
    if ($ddev !== '') {
      return $ddev . '-local';
    }

    return '';
  }

  /**
   * Reads an environment variable.
   *
   * Wrapped to make the override straightforward to test.
   *
   * @param string $name
   *   The environment variable name.
   *
   * @return string
   *   The value, or an empty string when unset.
   */
  protected function readEnv($name) {
    if (isset($_ENV[$name]) && is_string($_ENV[$name])) {
      return $_ENV[$name];
    }
    $value = getenv($name);
    return is_string($value) ? $value : '';
  }

}
