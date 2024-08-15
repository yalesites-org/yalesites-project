<?php

declare(strict_types=1);

namespace Drupal\ys_servicenow;

/**
 * Provides basic authentication using keys module for the HTTP resource.
 *
 * @Authentication(
 *   id = "basic_keys",
 *   title = @Translation("Basic Keys")
 * )
 */
class BasicAuthWithKeys {

  /**
   * The key ID.
   *
   * @var string
   */
  protected $keyId;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $configuration;

  /**
   * Constructs a new BasicAuthWithKeys object.
   *
   * @param \Drupal\Core\Config\Config $configuration
   *   The configuration.
   * @param string $key_id
   *   The key ID.
   */
  public function __construct($configuration, $key_id) {
    $this->keyId = $key_id;
    $this->configuration = $configuration;
  }

  /**
   * Get the authentication options.
   *
   * @return array
   *   The authentication options.
   */
  public function getAuthenticationOptions() {
    if (!$this->keyId) {
      throw new \Exception("Key not set");
    }
    $key = $this->getKey($this->keyId);
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
      throw new \Exception("Key has no value");
    }

    return json_decode($json_key);
  }

}
