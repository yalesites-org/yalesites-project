<?php

namespace Drupal\ys_views_basic;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for managing the Views Basic plugins.
 */
class ViewsBasicManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ViewsBasicManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns an array of content type machine names and the human readable name.
   */
  public function contentTypeList() {
    $contentTypes = [];

    $types = $this->entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();
    foreach ($types as $machine_name => $object) {
      $contentTypes[$machine_name] = $object->label();
    }

    return $contentTypes;
  }

  /**
   * Returns an array of view mode machine names and the human readable name.
   */
  public function viewModeList() {
    $viewModes = [];

    $view_modes = $this->entityTypeManager()
      ->getStorage('entity_view_mode')
      ->loadMultiple();
    foreach ($view_modes as $machine_name => $object) {
      $pattern = "/^node./";
      if (preg_match($pattern, $machine_name) && $object->status()) {
        $viewModes[$machine_name] = $object->label();
      }
    }

    return $viewModes;
  }

}
