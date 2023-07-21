<?php

namespace Drupal\ys_layouts\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_layouts\UpdateExistingNodes;

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

    $updateExistingNodes = new UpdateExistingNodes();
    $updateExistingNodes->updateExistingPostMeta();

    return [
      '#markup' => 'Hello, world',
    ];
  }

}
