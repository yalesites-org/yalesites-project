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
   */
  const LANDING_PAGE_TYPES = ['news', 'event'];

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
  public function __construct(BreadcrumbBuilderInterface $breadcrumb_manager, ConfigFactoryInterface $config_factory, PathValidator $path_validator, AliasManager $alias_manager, EntityTypeManagerInterface $entity_type_manager, MenuLinkManager $menu_link_manager) {
    $this->breadcrumbManager = $breadcrumb_manager;
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
   * Tests if the current content type is one of the special content types.
   *
   * For now, this tests news and events.
   *
   * Also validates the top link for the news/events.
   *
   * @return bool
   *   True if the top level is one in the list and is valid.
   */
  public function hasLandingPage($route) {
    $node = $route->getParameter('node');
    if($node && in_array($node->bundle(), self::LANDING_PAGE_TYPES)) {
      return TRUE;
    }
    return FALSE;
  }

}
