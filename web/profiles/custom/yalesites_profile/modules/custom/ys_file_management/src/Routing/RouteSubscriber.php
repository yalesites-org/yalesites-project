<?php

namespace Drupal\ys_file_management\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber to override entity_usage routes with our custom controller.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    // Override the media entity usage route to use our custom controller
    // that supports Layout Builder.
    $route_name = 'entity.media.entity_usage';
    if ($route = $collection->get($route_name)) {
      // Replace the controller with our custom one.
      $route->setDefault('_controller', '\Drupal\ys_file_management\Controller\YsMediaUsageController::listUsageLocalTask');
      $route->setDefault('_title_callback', '\Drupal\ys_file_management\Controller\YsMediaUsageController::getTitleLocalTask');
      $route->setRequirement('_custom_access', '\Drupal\ys_file_management\Controller\YsMediaUsageController::checkAccessLocalTask');
    }

    // You can add other entity types here if needed in the future.
    // For example, for nodes:
    // @code
    // if ($route = $collection->get('entity.node.entity_usage')) {
    //   $route->setDefault('_controller', '\Drupal\ys_file_management\Controller\YsMediaUsageController::listUsageLocalTask');
    // }
    // @endcode
  }

}
