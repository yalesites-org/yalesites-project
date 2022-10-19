<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the dashboard page.
 */
class DashboardController extends ControllerBase {

  /**
   * Dashboard page contents.
   */
  public function content() {
    return [
      '#theme' => 'ys_dashboard',
    ];
  }

}
