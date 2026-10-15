<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\ys_core\YaleSitesBreadcrumbsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block to display the breadcrumbs.
 *
 * @Block(
 *   id = "ys_breadcrumb_block",
 *   admin_label = @Translation("YaleSites Breadcrumbs"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class YaleSitesBreadcrumbBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * YaleSites Breadcrumbs Manager.
   *
   * @var \Drupal\ys_core\YaleSitesBreadcrumbsManager
   */
  protected $yaleSitesBreadcrumbsManager;

  /**
   * UrlGenerator.
   *
   * @var \Drupal\Core\Routing\UrlGenerator
   */
  protected $urlGenerator;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\ys_core\YaleSitesBreadcrumbsManager $yaleSitesBreadcrumbsManager
   *   The YaleSites custom breadcrumb manager.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL Generator class.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The URL Generator class.
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *   The menu link manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, YaleSitesBreadcrumbsManager $yaleSitesBreadcrumbsManager, UrlGeneratorInterface $url_generator, RouteMatchInterface $route_match, MenuLinkManagerInterface $menu_link_manager, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->yaleSitesBreadcrumbsManager = $yaleSitesBreadcrumbsManager;
    $this->urlGenerator = $url_generator;
    $this->routeMatch = $route_match;
    $this->menuLinkManager = $menu_link_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ys_core.yalesites_breadcrumbs_manager'),
      $container->get('url_generator'),
      $container->get('current_route_match'),
      $container->get('plugin.manager.menu.link'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $section_storage = $this->routeMatch->getParameter('section_storage');
    if (!$section_storage instanceof SectionStorageInterface) {
      $links = $this->buildLinks();
    }
    else {
      $entity = ($section_storage->getContextsDuringPreview()[YaleSitesTitleBreadcrumbBlock::ENTITY_CONTEXT] ?? NULL)?->getContextValue();
      $menus = $this->getBreadcrumbMenus();
      $links = $this->buildLayoutBuilderLinks($entity, $menus);
      // The block's cache varies only by route, so tie the preview trail to
      // the page and menus it is built from, or a renamed page keeps its old
      // title.
      $cacheability = (new CacheableMetadata())->addCacheTags([
        'config:menu_breadcrumb.settings',
        ...array_map(fn($menu) => "config:system.menu.$menu", $menus),
      ]);
      if ($entity) {
        $cacheability->addCacheableDependency($entity);
      }
    }

    $build = [
      '#theme' => 'ys_breadcrumb_block',
      '#items' => $links,
      '#always_show_breadcrumbs' => FALSE,
    ];
    if (isset($cacheability)) {
      $cacheability->applyTo($build);
    }
    return $build;
  }

  /**
   * Builds the preview trail on Layout Builder routes.
   *
   * Layout Builder re-renders its preview from its own form routes ("Configure
   * section", "Add block"), so a trail built from the current route describes
   * that form, not the page: "Home / Configure section", or nothing at all.
   * The breadcrumb builders read the global request rather than a route match
   * we could hand them, so build the trail for the page being laid out
   * directly: its menu trail, the same one menu_breadcrumb shows on the
   * published page, or "Home / <page>" when the page is not in a menu.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity being laid out, if Layout Builder supplied one.
   * @param string[] $menus
   *   Menus to look for the entity in, in order.
   *
   * @return array
   *   Breadcrumb items for the ys_breadcrumb_block template.
   */
  protected function buildLayoutBuilderLinks(?EntityInterface $entity, array $menus): array {
    $links = [
      [
        'title' => $this->t('Home'),
        'url' => $this->urlGenerator->generateFromRoute('<front>'),
        'is_active' => FALSE,
      ],
    ];
    if (!$entity) {
      return $links;
    }

    $trail = $this->getMenuTrail($entity, $menus) ?: [['title' => $entity->label()]];
    // The current page is the last crumb, unlinked, as on the front end.
    $current = array_pop($trail);
    return [
      ...$links,
      ...$trail,
      ['title' => $current['title'], 'url' => '', 'is_active' => TRUE],
    ];
  }

  /**
   * Gets the menus menu_breadcrumb builds trails from, in its order.
   *
   * @return string[]
   *   Menu machine names enabled in menu_breadcrumb.settings, by weight.
   */
  protected function getBreadcrumbMenus(): array {
    $menus = array_filter(
      $this->configFactory->get('menu_breadcrumb.settings')->get('menu_breadcrumb_menus') ?? [],
      fn($menu) => !empty($menu['enabled']),
    );
    uasort($menus, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return array_keys($menus);
  }

  /**
   * Gets the menu trail crumbs from the root down to the entity's own link.
   *
   * Mirrors how menu_breadcrumb picks the trail on the published page: the
   * first link pointing at the entity in the first menu that has one, then
   * its parents.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being laid out.
   * @param string[] $menus
   *   Menus to look in, in order.
   *
   * @return array
   *   Breadcrumb items, root first, or an empty array when the entity is not
   *   in any of the menus.
   */
  protected function getMenuTrail(EntityInterface $entity, array $menus): array {
    // A content type's default layout previews unsaved sample content, which
    // has no URL and so no menu link.
    if ($entity->isNew()) {
      return [];
    }
    $url = $entity->toUrl();
    foreach ($menus as $menu) {
      $menu_links = $this->menuLinkManager->loadLinksByRoute($url->getRouteName(), $url->getRouteParameters(), $menu);
      if ($menu_link = reset($menu_links)) {
        $parent_ids = array_reverse($this->menuLinkManager->getParentIds($menu_link->getPluginId()));
        return array_map(function ($id) {
          $link = $this->menuLinkManager->createInstance($id);
          return [
            'title' => $link->getTitle(),
            'url' => $link->getUrlObject()->toString(),
            'is_active' => FALSE,
          ];
        }, array_values($parent_ids));
      }
    }
    return [];
  }

  /**
   * Builds the trail for the current route from the breadcrumb manager.
   *
   * @return array
   *   Breadcrumb items for the ys_breadcrumb_block template.
   */
  protected function buildLinks(): array {
    $breadcrumbs = $this->yaleSitesBreadcrumbsManager->build($this->routeMatch);
    $links = [];

    // Process all breadcrumbs.
    foreach ($breadcrumbs as $breadcrumb) {
      array_push($links, [
        'title' => $breadcrumb->getText(),
        'url' => $breadcrumb->getUrl()->toString(),
        'is_active' => empty($breadcrumb->getUrl()->toString()),
      ]);
    }

    $entity = $this->routeMatch->getParameter('node');

    // Adds the post or event title to the end of the breadcrumbs.
    if ($this->yaleSitesBreadcrumbsManager->hasLandingPage($this->routeMatch)) {
      array_push($links, [
        'title' => $entity->getTitle(),
        'url' => $entity->toLink()->getUrl()->toString(),
        'is_active' => TRUE,
      ]);
    }

    return $links;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Add cachetag for main menu changes.
    return Cache::mergeTags(parent::getCacheTags(), [
      'config:system.menu.main',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Every new route this block will rebuild.
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
