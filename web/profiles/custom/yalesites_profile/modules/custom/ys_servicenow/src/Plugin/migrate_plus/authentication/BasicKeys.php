<?php

declare(strict_types=1);

namespace Drupal\ys_servicenow\Plugin\migrate_plus\authentication;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate_plus\AuthenticationPluginBase;

/**
 * Provides basic authentication using keys module for the HTTP resource.
 *
 * @Authentication(
 *   id = "basic_keys",
 *   title = @Translation("Basic Keys")
 * )
 */
class BasicKeys extends AuthenticationPluginBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getAuthenticationOptions(): array {
    $servicenow_config = \Drupal::config('ys_servicenow.settings');
    $servicenow_key_id = $servicenow_config->get('servicenow_auth_key');

    if (!$servicenow_key_id) {
      throw new \Exception("ServiceNow key not set");
    }

    $key = $this->getKey($servicenow_key_id);

    $key_object = $this->getKeyValues($key);

    return [
      'auth' => [
        $key_object->username,
        $key_object->password,
      ],
    ];
  }

  /**
   * Given a key ID, return the key object.
   *
   * @param string $key_id
   *   The key ID.
   *
   * @return \Drupal\key\KeyInterface
   *   The key object
   */
  protected function getKey($key_id) {
    $key = \Drupal::service('key.repository')->getKey($key_id);

    if (!$key) {
      throw new \Exception("Key '$key_id' not found");
    }

    return $key;
  }

  /**
   * Given a key, return the key values.
   *
   * @param \Drupal\key\KeyInterface $key
   *   The key object.
   *
   * @return object
   *   The key values.
   */
  protected function getKeyValues($key) {
    $json_key = $key->getKeyValue();

    if (!$json_key) {
      throw new \Exception("Key 'ServiceNow' has no value");
    }

    return json_decode($json_key);
  }

}
