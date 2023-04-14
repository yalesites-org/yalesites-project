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
    return $this->removeEmptyLinks($this->breadcrumbManager->build($route)->getLinks());
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
