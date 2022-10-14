<?php

namespace Drupal\ys_mail\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration overrides for ys_mail.
 *
 * Override the mailchimp_transactional API key with the value defined in the
 * secrets.json file. This approach keeps the secret value out of the the repo.
 * Overrides do not display in config forms but can be verified by running
 * `drush config-get mailchimp_transactional.settings --include-overridden`.
 */
class MailConfigOverrides implements ConfigFactoryOverrideInterface {

  /**
   * The config group to override.
   */
  const CONFIG_GROUP = 'mailchimp_transactional.settings';

  /**
   * The config item to override.
   */
  const CONFIG_KEY = 'mailchimp_transactional_api_key';

  /**
   * The relative path to the Terminus managed secrets file.
   */
  const SECRETS_PATH = 'private://secrets.json';

  /**
   * The file system interface.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new MailConfigOverrides object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    if (in_array(self::CONFIG_GROUP, $names)) {
      $overrides[self::CONFIG_GROUP] = [
        self::CONFIG_KEY => $this->getSecret(self::CONFIG_KEY),
      ];
    }
    return $overrides;
  }

  /**
   * Get a value from the Terminus Secret's managed file.
   *
   * @param string $key
   *   The name of the stored secret.
   *
   * @return string
   *   The value of the stored secret or an empty string.
   */
  protected function getSecret(string $key): string {
    // Get the path to the file created by the Terminus Secrets plugin.
    $path = $this->fileSystem->realpath(self::SECRETS_PATH);

    // Exit early if the file does not exit.
    if (!file_exists($path)) {
      return '';
    }

    // Decode the secrets file to access the values.
    $secrets = json_decode(file_get_contents($path), TRUE);
    return $secrets[$key] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'MailConfigOverrides';
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
