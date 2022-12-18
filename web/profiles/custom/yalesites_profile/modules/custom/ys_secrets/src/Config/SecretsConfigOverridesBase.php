<?php

namespace Drupal\ys_secrets\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\ys_secrets\SecretsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for configuration overrides from secrets.json.
 *
 * Pantheon uses a secrets.json file to store environmental variables and
 * sensative keys within the private files directory. YaleSites uses public
 * repositories so we do not share secrets like API keys in our config files.
 * Active configuration is overridden using the ConfigFactoryOverrideInterface.
 * This base class may be used to override configurations with values stored in
 * the secrets.json file at runtime.
 *
 * An exmaple class that implements this base class can be found at:
 * Drupal\ys_mail\Config\MailConfigOverrides.
 *
 * Want to test if the config override is working? Use drush to get the active
 * config with overrides `drush config-get {$configGroup} --include-overridden`.
 */
abstract class SecretsConfigOverridesBase implements ConfigFactoryOverrideInterface {

  /**
   * The config group to override.
   *
   * This is commonly the name of the yaml file in the exported configuration.
   *
   * @var string
   */
  protected $configGroup;

  /**
   * Secret mapping.
   *
   * @var array
   *
   * This maps the name of the configuration in Drupal to a named value in the
   * Patheon secrets.json file. Multiple values may be mapped per configGroup.
   *
   * Example:
   * @code
   * protected $mapping = [
   *   'site_key: 'google_recaptcha_v3_site_key'
   *   'secret_key' => 'google_recaptcha_v3_secret_key',
   * ];
   * @endcode
   */
  protected $mapping;

  /**
   * Secrets manager service.
   *
   * @var \Drupal\ys_secrets\SecretsManager
   */
  protected $secrets;

  /**
   * Constructs a new MailConfigOverrides object.
   *
   * @param \Drupal\ys_secrets\SecretsManager $secrets
   *   Secrets manager service.
   */
  public function __construct(SecretsManager $secrets) {
    $this->secrets = $secrets;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_secrets.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    if (in_array($this->configGroup, $names)) {
      foreach ($this->mapping as $drupalKey => $secretsKey) {
        $overrides[$this->configGroup][$drupalKey] = $this->secrets->get($secretsKey);
      }
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'SecretsConfigOverrides';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
