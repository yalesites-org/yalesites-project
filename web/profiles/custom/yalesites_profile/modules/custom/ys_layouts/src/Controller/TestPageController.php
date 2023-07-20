<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_layouts\UpdatePageTwoColumn;

/**
 * Provides route responses for the Example module.
 */
class TestPageController extends ControllerBase {

  /**
   * Returns a simple page.
   *
   * @return array
   *   A simple renderable array.
   */
  public function testPage() {

    $updatePageTwoColumn = new UpdatePageTwoColumn();
    $updatePageTwoColumn->updateExistingPages();

    return [
      '#markup' => 'Hello, world',
    ];
  }

}
