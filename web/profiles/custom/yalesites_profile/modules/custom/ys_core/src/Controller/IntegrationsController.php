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
      $output['#content'][$key]['#actions']['configure'] = [
        '#type' => 'link',
        '#title' => t('Configure'),
        '#url' => $output['#content'][$key]['url'],
        '#options' => [
          'attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ],
      ];

      $module_name = $this->getModuleNameFromRouteName($output['#content'][$key]['url']->getRouteName());
      if ($this->isTurnedOn($module_name)) {
        $output['#content'][$key]['#actions']['sync'] = [
          '#type' => 'link',
          '#title' => t('Sync now'),
          '#url' => Url::fromRoute($module_name . 'run_migrations'),
          '#options' => [
            'attributes' => [
              'class' => ['button', 'button--primary'],
            ],
          ],
        ];
      }
    }

    $output['#theme'] = 'ys_integrations_block';
    return $output;

  }

  /**
   *
   */
  protected function isTurnedOn($module_name) {
    $config = \Drupal::config($module_name . '.settings');
    $settings_name = $this->removeYsPrefix($module_name);
    return $config->get('enable_' . $settings_name . '_sync');
  }

  /**
   *
   */
  protected function getModuleNameFromRouteName($route_name) {
    $route_parts = explode('.', $route_name);
    return $route_parts[0];
  }

  /**
   *
   */
  protected function removeYsPrefix($name) {
    return str_replace('ys_', '', $name);
  }

}
