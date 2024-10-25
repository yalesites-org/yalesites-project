<?php

namespace Drupal\ys_campus_group\Plugin\migrate\source;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Plugin\migrate\source\Url;

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
    $client = \Drupal::httpClient();
    $config = \Drupal::configFactory()->getEditable('ys_campus_group.settings');
    $url = $config->get('campus_group_endpoint');
    $groups = $config->get('campus_group_groupids');
    $token = $config->get('campus_group_api_secret');
    $cookie = $config->get('campus_group_api_cookie');
    $agent = $config->get('campus_group_api_useragent');
    $days = $config->get('campus_group_future_days');

    if (empty($headers)) {
      $headers['User-Agent'] = $agent;
      $headers['X-CG-API-Secret'] = $token;
      $headers['Set-Cookie'] = $cookie;
    }

    $configuration['headers'] = $headers;
    $configuration['urls'] = $url . '?future_day_range=' . $days . '&group_ids=' . $groups;
    // Run the parent constructor.
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

}
