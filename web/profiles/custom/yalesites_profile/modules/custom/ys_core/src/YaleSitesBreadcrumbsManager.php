<?php

namespace Drupal\ys_core;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\PathValidator;
use Drupal\node\NodeInterface;

/**
 * Service for managing custom breadcrumbs for YaleSites.
 */
class YaleSitesBreadcrumbsManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleSettings;

  /**
   * The breadcrumb manager.
   *
   * @var \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface
   */
  protected $breadcrumbManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  protected $contentType;

  /**
   * PathValidator.
   *
   * @var \Drupal\Core\Path\PathValidator
   */
  protected $pathValidator;

  /**
   * List of special configs and content types - used for news/events.
   *
   * Usage: 'config.name.in.ys_core.site' => 'content_type_machine_name'.
   *
   * Do not add 'home' breadcrumb to news/events.
   *
   * Add the title of the node to news/events to end of breadcrumb.
   */
  const SPECIAL_CONTENT_TYPES = [
    'page.news' => 'news',
    'page.events' => 'event',
  ];

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface $breadcrumb_manager
   *   The breadcrumb manager.
   */
  public function __construct(BreadcrumbBuilderInterface $breadcrumb_manager, RouteMatchInterface $route_match, ConfigFactoryInterface $config_factory, PathValidator $path_validator) {
    $this->breadcrumbManager = $breadcrumb_manager;
    $this->routeMatch = $route_match;
    $this->contentType = $this->routeMatch->getParameter('node') ? $this->routeMatch->getParameter('node')->bundle() : NULL;
    $this->yaleSettings = $config_factory->get('ys_core.site');
    $this->pathValidator = $path_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('breadcrumb'),
      $container->get('current_route_match'),
      $container->get('config.factory'),
      $container->get('path.validator'),
    );
  }

  /**
   * Get a list of breadcrumb links using the Drupal BreadcrumbBuilder.
   *
   * @return \Drupal\Core\Link[]
   *   An array of Drupal links.
   */
  public function getCustomBreadcrumbs(): array {
    return $this->removeEmptyLinks($this->breadcrumbManager->build($this->routeMatch)->getLinks());
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

  public function isNewsEvent() {
    $isNewsEvent = FALSE;


    // Tests if we are on the content type and that the top level link is valid.
    foreach (self::SPECIAL_CONTENT_TYPES as $config => $type) {
      if ($this->contentType == $type && $this->validateTopLevelLink($this->yaleSettings->get($config))) {
        $isNewsEvent = TRUE;
      }
    }
    return $isNewsEvent;
  }

  public function currentPageNewsEvent() {
    return in_array($this->contentType, self::SPECIAL_CONTENT_TYPES);
  }

  /**
   * Gets the top level url and title of the news or events page.
   *
   * Checks to see if the page exists, is published, and is in a menu.
   *
   * @param string $topLevelUrl
   *   Top level path specified in config.
   */
  protected function validateTopLevelLink($topLevelUrl) {
    // Is this a valid path?
    if ($this->pathValidator->getUrlIfValid($topLevelUrl)) {

      // Gets the Drupal path and gets the node ID from that path.
      $path = \Drupal::service('path_alias.manager')->getPathByAlias($topLevelUrl);
      $params = Url::fromUri("internal:" . $path)->getRouteParameters();

      // Is this a valid node?
      if (isset($params['node'])) {

        // Is the node published?
        $nodePublished = \Drupal::entityTypeManager()->getStorage('node')->load($params['node'])->isPublished();

        // Is the node in the menu AND is it enabled?
        $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
        $menuItem = $menu_link_manager->loadLinksByRoute('entity.node.canonical', ['node' => $params['node']]);
        $menuItemData = array_pop($menuItem);
        $topLevelInMenu = empty($menuItemData) ? FALSE : $menuItemData->isEnabled();

        if ($nodePublished && $topLevelInMenu) {
          return $topLevelUrl;
        }
      }
    }
    return FALSE;
  }

  public function currentEntity() {
    if ($this->routeMatch->getRouteName() == 'entity.node.canonical') {
      $entity = $this->routeMatch->getParameter('node');
      if ($entity instanceof NodeInterface) {
        return $entity;
      }
    }
    return FALSE;
  }

}
