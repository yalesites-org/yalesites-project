<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
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
    $section_storage = $this->routeMatch->getParameter('section_storage');
    $links = $section_storage instanceof SectionStorageInterface
      ? $this->buildLayoutBuilderLinks($section_storage)
      : $this->buildLinks();

    return [
      '#theme' => 'ys_breadcrumb_block',
      '#items' => $links,
      '#always_show_breadcrumbs' => FALSE,
    ];
  }

  /**
   * Builds the preview trail on Layout Builder routes.
   *
   * Layout Builder re-renders its preview from its own form routes ("Configure
   * section", "Add block"), so a trail built from the current route describes
   * that form, not the page: "Home / Configure section", or nothing at all.
   * The breadcrumb builders read the global request rather than a route match
   * we could hand them, so name the page being laid out directly instead, the
   * same "Home / <page>" the preview shows on first load.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage the route is editing.
   *
   * @return array
   *   Breadcrumb items for the ys_breadcrumb_block template.
   */
  protected function buildLayoutBuilderLinks(SectionStorageInterface $section_storage): array {
    $links = [
      [
        'title' => $this->t('Home'),
        'url' => $this->urlGenerator->generateFromRoute('<front>'),
        'is_active' => FALSE,
      ],
    ];

    $entity = ($section_storage->getContextsDuringPreview()[YaleSitesTitleBreadcrumbBlock::ENTITY_CONTEXT] ?? NULL)?->getContextValue();
    if ($entity) {
      $links[] = [
        'title' => $entity->label(),
        'url' => '',
        'is_active' => TRUE,
      ];
    }

    return $links;
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
