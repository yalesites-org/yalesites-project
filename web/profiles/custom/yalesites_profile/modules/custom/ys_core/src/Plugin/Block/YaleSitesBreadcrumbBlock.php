<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Url;
use Drupal\Core\Path\PathValidator;
use Drupal\Core\Menu\MenuLinkManager;

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
   * UrlGenerator.
   *
   * @var \Drupal\Core\Routing\UrlGenerator
   */
  protected $urlGenerator;

  /**
   * PathValidator.
   *
   * @var \Drupal\Core\Path\PathValidator
   */
  protected $pathValidator;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface $breadcrumb_manager
   *   The breadcrumb manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL Generator class.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Drupal configuration.
   * @param \Drupal\Core\Path\PathValidator $path_validator
   *   Validates paths.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, BreadcrumbBuilderInterface $breadcrumb_manager, RouteMatchInterface $route_match, UrlGeneratorInterface $url_generator, ConfigFactoryInterface $config_factory, PathValidator $path_validator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->breadcrumbManager = $breadcrumb_manager;
    $this->routeMatch = $route_match;
    $this->urlGenerator = $url_generator;
    $this->yaleSettings = $config_factory->get('ys_core.site');
    $this->pathValidator = $path_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('breadcrumb'),
      $container->get('current_route_match'),
      $container->get('url_generator'),
      $container->get('config.factory'),
      $container->get('path.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $breadcrumbs = $this->removeEmptyLinks($this->getBreadcrumbs());
    $isNewsEvent = FALSE;
    $contentType = $this->routeMatch->getParameter('node') ? $this->routeMatch->getParameter('node')->bundle() : NULL;

    // Tests if we are on the content type and that the top level link is valid.
    foreach (self::SPECIAL_CONTENT_TYPES as $config => $type) {
      if ($contentType == $type && $this->validateTopLevelLink($this->yaleSettings->get($config))) {
        $isNewsEvent = TRUE;
      }
    }

    $links = [];
    // Only add the home link to pages that are not news/events.
    if (!in_array($contentType, self::SPECIAL_CONTENT_TYPES)) {
      $links = [
        [
          'title' => $this->t('Home'),
          'url' => $this->urlGenerator->generateFromRoute('<front>', []),
          'is_active' => FALSE,
        ],
      ];
    }

    foreach ($breadcrumbs as $breadcrumb) {
      array_push($links, [
        'title' => $breadcrumb->getText(),
        'url' => $breadcrumb->getUrl()->toString(),
        'is_active' => empty($breadcrumb->getUrl()->toString()),
      ]);
    }

    // Adds the news or event title to the end of the breadcrumbs.
    if ($isNewsEvent && $this->routeMatch->getRouteName() == 'entity.node.canonical') {
      $entity = $this->routeMatch->getParameter('node');
      if ($entity instanceof NodeInterface) {
        array_push($links, [
          'title' => $entity->getTitle(),
          'url' => $entity->toLink()->getUrl()->toString(),
          'is_active' => TRUE,
        ]);
      }
    }

    return [
      '#theme' => 'ys_breadcrumb_block',
      '#items' => $links,
    ];
  }

  /**
   * Get a list of breadcrumb links using the Drupal BreadcrumbBuilder.
   *
   * @return \Drupal\Core\Link[]
   *   An array of Drupal links.
   */
  protected function getBreadcrumbs(): array {
    return $this->breadcrumbManager->build($this->routeMatch)->getLinks();
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
