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
  const DAYS = 120;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    $config = \Drupal::configFactory()->getEditable('ys_campus_groups.settings');

    $url = $config->get('campus_groups_endpoint');
    $groups = $config->get('campus_groups_groupids');
    $days = self::DAYS;

    $queryParams = [
      'future_day_range' => $days,
      'group_ids' => $groups,
    ];

    $urlObject = UrlObject::fromUri($url, ['query' => $queryParams]);

    $configuration['urls'] = $urlObject->toString();

    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

}
