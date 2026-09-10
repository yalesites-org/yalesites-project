<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    $page_title = $this->getPageTitle();
    $breadcrumbs_placeholder = [];

    /*
     * For layout builder, during the block edit process, we want to show how
     * the breadcrumbs will look, but we are on a layout route, so getting
     * breadcrumbs is tricky. Instead we will show an example of how it may
     * look if the node is in the menu.
     */
    if ($route && str_ends_with($route->getPath(), 'layout')) {
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
      '#page_title_display' => $this->configuration['page_title_display'] ?? '',
      '#breadcrumbs_placeholder' => $breadcrumbs_placeholder,
    ];
  }

  /**
   * Gets the title to display for the page being rendered.
   *
   * The node is preferred over the route title because a node is rendered on
   * routes other than its canonical one, where the two disagree. Search API
   * renders the node in the request that saves it, so whatever this returns
   * is indexed as part of the page's content: on /node/{node}/layout the
   * route title is "Edit layout for <label>", and on a revision route it is
   * "Revision of <label> from <date>".
   *
   * A revision route renders the revision rather than the default node, so
   * that parameter is preferred where the route carries one.
   *
   * Known limitation: a node saved on a route that carries no node at all --
   * /node/add/{type} for a new page, or a bulk operation from
   * /admin/content -- still falls through to the route title. Closing that
   * needs the block to know which entity it is rendering inside rather than
   * which one the route names; see the issue for why that is a much larger
   * change.
   *
   * @return array|string|\Stringable|null
   *   The page title, or an empty string when neither source resolves one.
   */
  protected function getPageTitle() {
    foreach (['node_revision', 'node'] as $parameter) {
      $node = $this->routeMatch->getParameter($parameter);
      if ($node instanceof NodeInterface) {
        return $node->label();
      }
    }

    // Fall back to the route title where there is no node in context, so the
    // block keeps working on non-node pages.
    $route = $this->routeMatch->getRouteObject();
    $request = $this->requestStack->getCurrentRequest();
    if ($route && $request) {
      return $this->titleResolver->getTitle($request, $route);
    }

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) : array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    // The form field is defined and added to the form array here.
    $form['page_title_display'] = [
      '#type' => 'select',
      '#title' => $this->t('Title Display'),
      '#default_value' => $config['page_title_display'] ?? '',
      '#options' => [
        'visible' => $this->t('Display Title: Your page title is visible and used as the H1'),
        'visually-hidden' => $this->t('Visually Hidden: Hide your page title without impacting site accessibility'),
        'hidden' => $this->t('Hide Title: Should only be used when a page’s banner Block title is set to H1'),
      ],
      '#description' => $this->t('For more information about conditional banner titles, <a href="https://yalesites.yale.edu/posts/2023-10-17-community-spotlight-yale-united-way-campaign#tips" target="_blank">view our tips and tricks on this topic</a>.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) : void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['page_title_display'] = $form_state->getValue('page_title_display');
  }

}
