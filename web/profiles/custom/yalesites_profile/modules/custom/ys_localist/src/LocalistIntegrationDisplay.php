<?php

namespace Drupal\ys_localist;

use Drupal\ys_core\IntegrationDisplayBase;
use Drupal\Core\Url;

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

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_localist.settings');
  }

}
