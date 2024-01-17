<?php

namespace Drupal\ys_templated_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for allowing users to create nodes based on templated content.
 */
class TemplatedContentController extends ControllerBase {

  /**
   * Constructs the controller object.
   */
  public function __construct() {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static();
  }

}
