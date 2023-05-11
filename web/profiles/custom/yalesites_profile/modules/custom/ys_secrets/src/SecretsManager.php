<?php

namespace Drupal\ys_secrets;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Secets management service.
 *
 * Utilities for interacting with the Terminus managed Pantheon secrets file.
 * The 'secrets.json' file should sit on all Pantheon hosted websites. Values
 * are added to this file using the Terminus secrets plugin. This management
 * service is used to get values from this secrets file.
 */
class SecretsManager implements ContainerInjectionInterface {

  /**
   * The relative path to the secrets file.
   */
  const SECRETS_PATH = 'private://secrets.json';

  /**
   * The file system interface.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new SecretsManager object.
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
   * Get a value from the secrets file.
   *
   * @param string $key
   *   The name of the stored secret.
   *
   * @return string
   *   The value of the stored secret or an empty string.
   */
  public function get(string $key): string {
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
   * Disable a field in the Drupal admin UI.
   *
   * @param array $field
   *   A field defined by the forms API.
   */
  public function disableField(array &$field) {
    // Disable the field since the value is being overridden.
    $field['#attributes']['disabled'] = 'disabled';
    // Let future developers know why this field is disabled.
    $field['#description'] = $field['#description'] .
    '<br>This is set with a value stored in the Pantheon secrets.json file.';
  }

}
