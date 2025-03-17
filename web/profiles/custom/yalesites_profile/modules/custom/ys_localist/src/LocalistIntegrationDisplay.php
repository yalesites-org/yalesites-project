<?php

namespace Drupal\ys_localist;

use Drupal\ys_core\IntegrationDisplayBase;

/**
 * Tells integrations things about Localist.
 */
class LocalistIntegrationDisplay extends IntegrationDisplayBase {

  /**
   * {@inheritdoc}
   */
  const INTEGRATION_NAME = 'ys_localist.settings';

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    return $this->config->get('enable_localist_sync');
  }

}
