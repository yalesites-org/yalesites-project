<?php

namespace Drupal\ys_content_export\Plugin\Menu\LocalAction;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Menu\LocalActionDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Export local action that carries the Manage page's active filters.
 *
 * The Manage views submit their exposed filters as query-string parameters. A
 * plain local action would drop them, so the export would always dump the full
 * unfiltered list. Forwarding the current request's query (minus the pager
 * `page`) makes the export URL match what the editor is looking at; the
 * controller then replays that query through the view's exposed input.
 */
class ContentExportLocalAction extends LocalActionDefault {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteProviderInterface $route_provider, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $route_provider);
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
      $container->get('router.route_provider'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(RouteMatchInterface $route_match) {
    $options = parent::getOptions($route_match);
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];
    // The export is unpaged, so the Manage view's pager position is irrelevant.
    unset($query['page']);
    if ($query) {
      $options['query'] = $query;
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The link now varies by the active exposed-filter query string, so the
    // rendered action must not be reused across different filter states.
    return Cache::mergeContexts(parent::getCacheContexts(), ['url.query_args']);
  }

}
