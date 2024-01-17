<?php

namespace Drupal\ys_templated_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for allowing users to create nodes based on templated content.
 */
class TemplatedContentController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Constructs the controller object.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('current_user'));
  }

  /**
   * Builds the content selection form.
   */
  public function build() {
    // Get form builder.
    $formBuilder = $this->formBuilder();

    return $formBuilder->getForm('Drupal\ys_templated_content\Form\TemplatedContentForm');
  }

}
