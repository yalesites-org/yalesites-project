<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Give me a description.
 */
class DashboardController extends ControllerBase {

  /**
   * Returns a render-able array for a test page.
   */
  public function content() {

    // Do something with your variables here.
    $myText = 'This is not just a default text!';
    $myNumber = 1;
    $myArray = [1, 2, 3];

    return [
      // Your theme hook name.
      '#theme' => 'ys_dashboard_theme_hook',
      // Your variables.
      '#variable1' => $myText,
      '#variable2' => $myNumber,
      '#variable3' => $myArray,
    ];
  }
}
