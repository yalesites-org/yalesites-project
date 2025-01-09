<?php

namespace Drupal\ys_campus_groups\Plugin\migrate\source;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Plugin\migrate\source\Url;
use Drupal\Core\Url as UrlObject;

/**
 * Source plugin for retrieving data via URLs, securely.
 *
 * @MigrateSource(
 *   id = "campus_group_url"
 * )
 */
class CampusGroupUrl extends Url {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    $config = \Drupal::configFactory()->getEditable('ys_campus_group.settings');
    $url = $config->get('campus_group_endpoint');
    $groups = $config->get('campus_group_groupids');
    $token = $config->get('campus_group_api_secret');
    $cookie = $config->get('campus_group_api_cookie');
    $agent = $config->get('campus_group_api_useragent');
    $days = $config->get('campus_group_future_days');
    $headers = $configuration['headers'];

    $queryParams = [
      'future_day_range' => $days,
      'group_ids' => $groups,
    ];

    $headerItems = [
      'User-Agent' => $agent,
      'X-CG-API-Secret' => $token,
      'Set-Cookie' => $cookie,
    ];

    foreach ($headerItems as $key => $value) {
      if (empty($headers[$key])) {
        $headers[$key] = $value;
      }
    }

    $urlObject = UrlObject::fromUri($url, ['query' => $queryParams]);

    $configuration['headers'] = $headers;
    $configuration['urls'] = $urlObject->toString();

    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

}
