<?php

namespace Drupal\ys_core;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Core\Routing\RouteMatch;

/**
 * Twig functions to retrieve breadcrumbs for a specific node.
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

        kint($nid);
        kint($breadcrumbs);

        // // Process all breadcrumbs.
        // foreach ($breadcrumbs['#links'] as $breadcrumb) {
        //   array_push($links, [
        //     'title' => $breadcrumb->getText(),
        //     'url' => $breadcrumb->getUrl()->toString(),
        //     'is_active' => empty($breadcrumb->getUrl()->toString()),
        //   ]);
        // }
        // return $links;
      }
    }
  }

}
