<?php

namespace Drupal\ys_book\Controller;

use Drupal\book\Controller\BookController;

/**
 * Changes text that comes from the book controller.
 */
class YsBookController extends BookController {

  /**
   * Overrides the book admin overview page.
   *
   * @return array
   *   A render array for the admin overview page.
   */
  public function adminOverview() {
    $build = parent::adminOverview();

    if (isset($build['#header'])) {
      foreach ($build['#header'] as &$header) {
        if ($header->__toString() === 'Book') {
          $header = $this->t('Collection');
        }
      }
    }

    return $build;
  }

}
