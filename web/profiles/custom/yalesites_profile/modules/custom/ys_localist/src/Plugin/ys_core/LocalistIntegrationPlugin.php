<?php

namespace Drupal\ys_localist\Plugin\ys_core;

use Drupal\ys_core\IntegrationPluginBase;
use Drupal\ys_core\Attribute\Integration;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
* Provides a localist integration plugin.
*/
#[Integration(
  id: 'ys_localist',
  label: new TranslatableMarkup('Localist'),
  description: new TranslatableMarkup('Provides integration with the Localist API.'),
)]
class LocalistIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_localist.settings');
    return $config->get('enable_localist_sync');
  }

}
