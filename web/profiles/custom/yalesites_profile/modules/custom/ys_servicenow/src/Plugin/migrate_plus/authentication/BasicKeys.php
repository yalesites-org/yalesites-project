<?php

use Drupal\migrate_plus\Plugin\migrate_plus\authentication\Basic;

declare(strict_types=1);

namespace Drupal\ys_servicenow\Plugin\migrate_plus\authentication;

use Drupal\migrate_plus\Plugin\migrate_plus\authentication\Basic;

/**
 * Provides basic authentication using keys module as username and password.
 *
 * @Authentication(
 *   id = 'basic_keys',
 *   title = @Translation("Basic Keys")
 * )
 */
class BasicKeys extends Basic {

  /**
   * {@inheritdoc}
   */
  public function getAuthenticationOptions(): array {
    $key = $this->getKey('ServiceNow');

    $key_object = $this->getKeyValues($key);

    $username = $key_object->username;
    $password = $key_object->password;

    // Get the key from keys module called "ServiceNow".
    return [
      'auth' => [
        $username,
        $password,
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
