<?php

namespace Drupal\ys_core;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Drupal\Core\Routing\RouteMatch;

/**
 * Twig functions to retrieve breadcrumbs for a specific node.
 *
 * @todo This extension is not complete and more work needs to be done here.
 * Main issue is that the core breadcrumb service seems to ignore the passed
 * route parameter so getting breadcrumbs for a specific node does not work.
 * Instead, it only works with the current route. More details and a possible
 * fix here: https://drupal.stackexchange.com/questions/191548/how-do-i-build-breadcrumbs-for-a-certain-node
 * and here: https://www.drupal.org/project/menu_breadcrumb/issues/3026188
 */
class SearchBreadcrumbTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('getBreadcrumbsFromNid', [$this, 'getBreadcrumbsFromNid']),
    ];
  }

  /**
   * Function that returns breadcrumbs for a specified node ID.
   *
   * @param string $nid
   *   Node ID.
   */
  public function getBreadcrumbsFromNid($nid) {
    $links = [];

    if (!empty($nid)) {
      // Is this node in the menu?
      $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
      $result = $menu_link_manager->loadLinksByRoute('entity.node.canonical', ['node' => $nid]);

      if (!empty($result)) {
        // Get the breadcrumbs for this node.
        $entity = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
        $routeName = $entity->toUrl()->getRouteName();
        $routeParameters = $entity->toUrl()->getRouteParameters();
        $route = \Drupal::service('router.route_provider')->getRouteByName($routeName);
        $routeMatch = new RouteMatch($routeName, $route, $routeParameters, $routeParameters);

        $breadcrumbs = \Drupal::service('breadcrumb')->build($routeMatch)->getLinks();

      }
    }
  }

}
