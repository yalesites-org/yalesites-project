<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, YaleSitesBreadcrumbsManager $yaleSitesBreadcrumbsManager, UrlGeneratorInterface $url_generator, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->yaleSitesBreadcrumbsManager = $yaleSitesBreadcrumbsManager;
    $this->urlGenerator = $url_generator;
    $this->routeMatch = $route_match;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
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

    // Adds the post or event title to the end of the breadcrumbs.
    if ($this->yaleSitesBreadcrumbsManager->hasLandingPage($this->routeMatch)) {
      $entity = $this->routeMatch->getParameter('node');
      array_push($links, [
        'title' => $entity->getTitle(),
        'url' => $entity->toLink()->getUrl()->toString(),
        'is_active' => TRUE,
      ]);
    }

    return [
      '#theme' => 'ys_breadcrumb_block',
      '#items' => $links,
    ];
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
