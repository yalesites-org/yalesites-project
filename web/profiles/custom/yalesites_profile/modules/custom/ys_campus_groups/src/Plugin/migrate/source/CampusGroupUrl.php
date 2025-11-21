<?php

namespace Drupal\ys_campus_groups\Plugin\migrate\source;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Plugin\migrate\source\Url;
use Drupal\Core\Url as UrlObject;

/**
 * Source plugin for retrieving data via URLs, securely.
 *
 * @MigrateSource(
 *   id = "campus_groups_url"
 * )
 */
class CampusGroupUrl extends Url {
  // The number of days in the future to retrieve events.
  const DAYS = 365;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    $config = \Drupal::configFactory()->getEditable('ys_campus_groups.settings');

    $url = $config->get('campus_groups_endpoint');
    $api_key_name = $config->get('campus_groups_api_key');
    $groups = $config->get('campus_groups_groupids');
    $days = self::DAYS;

    if ($url) {
      $queryParams = [
        'future_day_range' => $days,
        'group_ids' => $groups,
      ];

      $urlObject = UrlObject::fromUri($url, ['query' => $queryParams]);

      $configuration['urls'] = $urlObject->toString();
      $headers = $configuration['headers'] ?? [];

      $api_key = $this->getApiKeyFromKeysModule($api_key_name);

      if ($api_key) {
        $headers['x-cg-api-secret'] = $api_key;
        $configuration['headers'] = $headers;
      }
    }

    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * Retrieves the API key from the keys module.
   *
   * @param string $key_name
   *   The key name.
   */
  protected function getApiKeyFromKeysModule($key_name) {
    $key = \Drupal::service('key.repository')->getKey($key_name);

    if (!$key) {
      return NULL;
    }

    $key_value = $key->getKeyValue();
    return $key_value;
  }

}
