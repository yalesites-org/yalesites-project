<?php

namespace Drupal\ys_core;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkManager;
use Drupal\Core\Path\PathValidator;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing custom breadcrumbs for YaleSites.
 */
class YaleSitesBreadcrumbsManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * List of special configs and content types - used for news/events.
   *
   * Usage: 'config.name.in.ys_core.site' => 'content_type_machine_name'.
   */
  const SPECIAL_CONTENT_TYPES = [
    'page.news' => 'news',
    'page.events' => 'event',
  ];

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

  /**
   * The calculated content type machine name.
   *
   * @var string
   */
  protected $contentType;

  /**
   * PathValidator.
   *
   * @var \Drupal\Core\Path\PathValidator
   */
  protected $pathValidator;

  /**
   * AliasManager.
   *
   * @var \Drupal\path_alias\AliasManager
   */
  protected $aliasManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManager
   */
  protected $menuLinkManager;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface $breadcrumb_manager
   *   The breadcrumb manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Drupal configuration.
   * @param \Drupal\Core\Path\PathValidator $path_validator
   *   Validates Drupal paths.
   * @param \Drupal\path_alias\AliasManager $alias_manager
   *   The path alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Menu\MenuLinkManager $menu_link_manager
   *   The menu link manager.
   */
  public function __construct(BreadcrumbBuilderInterface $breadcrumb_manager, RouteMatchInterface $route_match, ConfigFactoryInterface $config_factory, PathValidator $path_validator, AliasManager $alias_manager, EntityTypeManagerInterface $entity_type_manager, MenuLinkManager $menu_link_manager) {
    $this->breadcrumbManager = $breadcrumb_manager;
    $this->routeMatch = $route_match;
    $this->contentType = $this->routeMatch->getParameter('node') ? $this->routeMatch->getParameter('node')->bundle() : NULL;
    $this->yaleSettings = $config_factory->get('ys_core.site');
    $this->pathValidator = $path_validator;
    $this->aliasManager = $alias_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->menuLinkManager = $menu_link_manager;
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
      $container->get('path_alias.manager'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.menu.link'),
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

  /**
   * Tests if the current content type is one of the special content types.
   *
   * For now, this tests news and events.
   *
   * Also validates the top link for the news/events.
   *
   * @return bool
   *   True if the top level is one in the list and is valid.
   */
  public function isNewsEvent() {
    $isNewsEvent = FALSE;

    foreach (self::SPECIAL_CONTENT_TYPES as $config => $type) {
      if ($this->contentType == $type && $this->validateTopLevelLink($this->yaleSettings->get($config))) {
        $isNewsEvent = TRUE;
      }
    }
    return $isNewsEvent;
  }

  /**
   * Tests if the current content type is one of the special content types.
   *
   * @return bool
   *   True if the current page is one of the special content types.
   */
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
      $path = $this->aliasManager->getPathByAlias($topLevelUrl);
      $params = Url::fromUri("internal:" . $path)->getRouteParameters();

      // Is this a valid node?
      if (isset($params['node'])) {

        // Is the node published?
        $nodePublished = $this->entityTypeManager->getStorage('node')->load($params['node'])->isPublished();

        // Is the node in the menu AND is it enabled?
        $menuItem = $this->menuLinkManager->loadLinksByRoute('entity.node.canonical', ['node' => $params['node']]);
        $menuItemData = array_pop($menuItem);
        $topLevelInMenu = empty($menuItemData) ? FALSE : $menuItemData->isEnabled();

        if ($nodePublished && $topLevelInMenu) {
          return $topLevelUrl;
        }
      }
    }
    return FALSE;
  }

  /**
   * Tests if the current current page is a node. If so, return the node info.
   *
   * @return Drupal\node\NodeInterface
   *   Returns node information or false.
   */
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
