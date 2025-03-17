<?php

namespace Drupal\ys_core\Controller;

use Drupal\system\Controller\SystemController;
use Drupal\Core\Url;

/**
 * Controller routines for system integrations routes.
 */
class IntegrationsController extends SystemController {

  /**
   *
   */
  public function systemAdminMenuBlockPage() {
    $output = parent::systemAdminMenuBlockPage();

    foreach ($output['#content'] as $key => $value) {
      $output['#content'][$key]['#actions']['sync'] = [
        '#type' => 'link',
        '#title' => t('Sync'),
        '#url' => Url::fromRoute('ys_core.admin_yalesites_integrations'),
      ];
    }

    $output['#theme'] = 'ys_integrations_block';
    return $output;
  }

}
