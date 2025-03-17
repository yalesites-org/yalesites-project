<?php

namespace Drupal\ys_campus_groups;

use Drupal\ys_core\IntegrationDisplayBase;

/**
 * Tells integrations things about Localist.
 */
class CampusGroupsIntegrationDisplay extends IntegrationDisplayBase {

  /**
   * {@inheritdoc}
   */
  const INTEGRATION_NAME = 'ys_campus_groups.settings';

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    return $this->config->get('enable_campus_groups_sync');
  }

}
