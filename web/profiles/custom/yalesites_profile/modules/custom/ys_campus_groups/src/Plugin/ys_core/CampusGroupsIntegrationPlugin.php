<?php

namespace Drupal\ys_campus_groups\Plugin\ys_core;

use Drupal\ys_core\IntegrationPluginBase;
use Drupal\ys_core\Attribute\Integration;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
* Provides a campus groups integration plugin.
*/
#[Integration(
  id: 'ys_campus_groups',
  label: new TranslatableMarkup('Campus Groups'),
  description: new TranslatableMarkup('Provides integration with the Campus Groups API.'),
)]
class CampusGroupsIntegrationPlugin extends IntegrationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    $config = $this->configFactory->get('ys_campus_groups.settings');
    return $config->get('enable_campus_groups_sync');
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_campus_groups.settings');
  }

}
