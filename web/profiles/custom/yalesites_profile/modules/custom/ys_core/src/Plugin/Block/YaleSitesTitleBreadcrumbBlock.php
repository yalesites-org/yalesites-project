<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Controller\TitleResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds a title and breadcrumb block.
 *
 * @Block(
 *   id = "ys_title_breadcrumb_block",
 *   admin_label = @Translation("YaleSites Page Title and Breadcrumb Block"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class YaleSitesTitleBreadcrumbBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Controller\TitleResolver
   */
  protected $titleResolver;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Controller\TitleResolver $title_resolver
   *   The title resolver.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, TitleResolver $title_resolver, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('title_resolver'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $route = $this->routeMatch->getRouteObject();
    $request = $this->requestStack->getCurrentRequest();

    // Get the page title.
    if ($route) {
      $page_title = $this->titleResolver->getTitle($request, $route);
    };

    /*
     * For layout builder, during the block edit process, we want to show how
     * the breadcrumbs will look, but we are on a layout route, so getting
     * breadcrumbs is tricky. Instead we will show an example of how it may
     * look if the node is in the menu.
     */

    $breadcrumbs_placeholder = [];
    if (str_ends_with($route->getPath(), 'layout')) {

      $page_title = $request->attributes->get('node')->getTitle();

      $breadcrumbs_placeholder = [
        [
          'title' => 'Home',
        ],
        [
          'title' => 'Example Breadcrumbs',
        ],
        [
          'title' => 'Only Shown',
        ],
        [
          'title' => 'If In Menu',
          'is_active' => TRUE,
        ],
      ];
    }

    return [
      '#theme' => 'ys_title_breadcrumb',
      '#page_title' => $page_title,
      '#breadcrumbs_placeholder' => $breadcrumbs_placeholder,
    ];
  }

}
