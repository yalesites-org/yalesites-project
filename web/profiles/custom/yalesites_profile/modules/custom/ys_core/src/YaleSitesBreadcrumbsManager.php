<?php

namespace Drupal\ys_core;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing custom breadcrumbs for YaleSites.
 */
class YaleSitesBreadcrumbsManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * List of special configs and content types - used for posts/events.
   */
  const LANDING_PAGE_TYPES = ['post', 'event'];

  /**
   * The breadcrumb manager.
   *
   * @var \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface
   */
  protected $breadcrumbManager;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface $breadcrumb_manager
   *   The breadcrumb manager.
   */
  public function __construct(BreadcrumbBuilderInterface $breadcrumb_manager) {
    $this->breadcrumbManager = $breadcrumb_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('breadcrumb'),
    );
  }

  /**
   * Get a list of breadcrumb links using the Drupal BreadcrumbBuilder.
   *
   * @return \Drupal\Core\Link[]
   *   An array of Drupal links.
   */
  public function build($route): array {
    if ($this->isLayoutBuilderMoveEndpoint($route)) {
      return [];
    }

    return $this->removeEmptyLinks($this->breadcrumbManager->build($route)->getLinks());
  }

  /**
   * Checks whether a route is Layout Builder's block-move endpoint.
   *
   * Core's PathBasedBreadcrumbBuilder resolves a title for every ancestor of
   * the request path. layout_builder.move_block is the only route under
   * /layout_builder/ with more than eight segments, so it is the only one with
   * an eight-segment ancestor - and that ancestor matches
   * layout_builder.move_block_form, whose title callback reads the segment as
   * a component UUID when the move URL puts a region name there.
   * Section::getComponent() then throws, 500-ing the whole AJAX response after
   * the move has already been written to the tempstore, which is what strands
   * the page with stale contextual links.
   *
   * Scoped to this one route on purpose. Every sibling endpoint (add_block,
   * update_block, remove_block, configure_section, and ys_layouts' clone and
   * detach) is eight segments or fewer, so no ancestor of theirs matches a
   * route with a throwing title callback - add_block and update_block carry a
   * plain _title string. Skipping breadcrumbs for them too would blank the
   * breadcrumb component in the Layout Builder preview on paths that work.
   *
   * The trade-off that remains: after a move, the rebuilt preview renders the
   * breadcrumb component empty until the next full page load. That is worth a
   * great deal less than a 500 on every drag.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route
   *   The route match to check.
   *
   * @return bool
   *   TRUE if breadcrumbs should be skipped for this route.
   */
  protected function isLayoutBuilderMoveEndpoint($route): bool {
    return $route->getRouteName() === 'layout_builder.move_block';
  }

  /**
   * Remove empty links from a list of links.
   *
   * @param \Drupal\Core\Link[] $links
   *   An array of Drupal links.
   *
   * @return \Drupal\Core\Link[]
   *   An array of Drupal links with empty ones removed.
   */
  protected function removeEmptyLinks(array $links): array {
    return array_filter($links, function ($link) {
      return $link->getText() !== '';
    });
  }

  /**
   * Tests if the current content type is one of the landing page types.
   *
   * For now, this tests posts and events.
   *
   * @return bool
   *   True if the route is one of the landing page types.
   */
  public function hasLandingPage($route) {
    $node = $route->getParameter('node');
    if ($node && in_array($node->bundle(), self::LANDING_PAGE_TYPES)) {
      return TRUE;
    }
    return FALSE;
  }

}
