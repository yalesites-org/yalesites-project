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
 * The context slot is named "layout_builder.entity" rather than given a short
 * local name such as "entity", and that name is load-bearing.
 * \Drupal\Core\Plugin\Context\ContextHandler::applyContextMapping() resolves a
 * slot against the plugin's stored context_mapping and falls back to the
 * slot's OWN name when the mapping has no entry for it, so for an unmapped
 * slot the name decides which of Layout Builder's contexts it picks up.
 *
 * Layout Builder offers the rendered entity under different keys depending on
 * the path. LayoutBuilderEntityViewDisplay supplies both "entity" and
 * "layout_builder.entity" while rendering, but in the Layout Builder preview
 * OverridesSectionStorage::getContextsDuringPreview() copies "entity" to
 * "layout_builder.entity" and then unsets "entity", and
 * DefaultsSectionStorage::getContextsDuringPreview() only ever sets
 * "layout_builder.entity". That key is therefore the only one present on every
 * path, so an unmapped slot named after it resolves everywhere.
 *
 * This is what avoids a data update, which is the reason this approach was
 * previously passed over. Core's own field blocks use a slot named "entity"
 * plus a stored context_mapping of {entity: layout_builder.entity}, written at
 * placement time by LayoutBuilderEntityViewDisplay::setComponent() precisely
 * because a bare "entity" slot would not resolve during preview. Every page
 * that already carries this block stored an EMPTY context_mapping, so taking
 * that route would mean writing the mapping into the layout_builder__layout
 * field of every node with an overridden layout on every site, and any node
 * the update missed would silently keep the bug.
 *
 * @Block(
 *   id = "ys_title_breadcrumb_block",
 *   admin_label = @Translation("YaleSites Page Title and Breadcrumb Block"),
 *   category = @Translation("YaleSites Core"),
 *   context_definitions = {
 *     "layout_builder.entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity being viewed"),
 *       required = FALSE
 *     )
 *   }
 * )
 */
class YaleSitesTitleBreadcrumbBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Name of the context slot holding the entity Layout Builder is rendering.
   *
   * Matches the context ID Layout Builder publishes, so that an empty stored
   * context_mapping still resolves. Keep in sync with the plugin annotation,
   * which cannot reference this constant.
   */
  const ENTITY_CONTEXT = 'layout_builder.entity';

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
   * The entity being rendered is preferred over anything the route names,
   * because a node is rendered on routes other than its canonical one -- and
   * sometimes on a route that names a different node, or no node at all.
   * Search API renders the node in the request that saves it, so whatever this
   * returns is indexed as part of the page's content. That made the route the
   * wrong source in four ways: on /node/{node}/layout the route title is "Edit
   * layout for <label>", on a revision route "Revision of <label> from
   * <date>", on /node/add/{type} (the first save of every new page) "Create
   * <type>", and on a bulk operation from /admin/content "Content". Rendering
   * one page while the request sits on another page's layout route indexed the
   * other page's title.
   *
   * Layout Builder hands the rendered entity to the block as a context, which
   * is true regardless of the route, so it is the first choice. It covers node
   * preview too: preview renders through a Layout Builder display, so the
   * context is supplied even though the route names the node as node_preview
   * rather than node.
   *
   * The route-parameter and route-title tiers below it are kept but are not
   * expected to be reached, because these blocks are placed only in entity
   * view displays and Layout Builder always supplies the context there. They
   * are insurance against rendering no heading at all -- a missing <h1> is a
   * WCAG 2.1 AA problem, so degrading to the old behaviour beats degrading to
   * nothing -- and they cover the window between a code deploy and the cache
   * rebuild that follows it, when the cached plugin definition can still
   * predate this context slot.
   *
   * @return array|string|\Stringable|null
   *   The page title, or an empty string when no source resolves one.
   */
  protected function getPageTitle() {
    // Read through getContexts() rather than getContextValue() so a plugin
    // definition cached before this slot existed cannot throw during the
    // window between a code deploy and the cache rebuild that follows it.
    $context = $this->getContexts()[self::ENTITY_CONTEXT] ?? NULL;
    $entity = $context ? $context->getContextValue() : NULL;
    if ($entity instanceof NodeInterface) {
      return $entity->label();
    }

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
   *
   * Removes the context-assignment select that BlockBase adds for any plugin
   * declaring a context. Editors open this form routinely to set Title
   * Display, and the Page Meta section is not locked against block update, so
   * the select would be visible to them -- listing raw context IDs on a form
   * the platform deliberately keeps plain. There is also nothing to choose:
   * this block always wants the entity Layout Builder is rendering, and
   * picking one of the route-derived entity contexts instead would store a
   * context_mapping that reinstates the very bug this resolves, on that page
   * only and invisibly. Leaving the mapping empty also keeps this change
   * revertible: a stored mapping naming a slot a reverted plugin no longer
   * declares makes applyContextMapping() throw.
   *
   * ConfigureBlockFormBase::submitForm() reads context_mapping with a default
   * of [], so removing the element stores the empty mapping unchanged.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    unset($form['context_mapping']);

    return $form;
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
