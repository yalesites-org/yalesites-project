<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block that renders the current node's Related Content list.
 *
 * Embeds the entity_reference_for_fields view (embed_related_content
 * display) with the current node id passed as the contextual filter, and
 * exposes a single configurable heading shown above the rendered list.
 *
 * Hides itself entirely when the route node has no field_related_content
 * or the field is empty, so the locked Related Resources Layout Builder
 * section collapses for nodes that haven't picked any related items.
 *
 * @Block(
 *   id = "related_content_block",
 *   admin_label = @Translation("Related Content"),
 *   category = @Translation("YaleSites Layouts"),
 *   description = @Translation("Displays a curated list of related content as cards, sourced from the current page's Related Content field."),
 * )
 */
class RelatedContentBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new RelatedContentBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'heading' => 'Related Content',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    // Required so the rendered list always has a parent heading. The cards
    // inside the block render as h3, so an empty/missing heading would skip
    // a level and trigger an a11y violation (WCAG 1.3.1).
    $form['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Heading'),
      '#description' => $this->t('Heading shown above the related content list.'),
      '#default_value' => $config['heading'] ?? 'Related Content',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    $this->configuration['heading'] = $form_state->getValue('heading');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getCurrentNode();

    // Bail when not on a node route, when the node has no related-content
    // field, or when the field is empty. Returning an empty render array lets
    // Drupal collapse the locked LB section instead of rendering a heading
    // with nothing under it.
    if (
      !($node instanceof NodeInterface)
      || !$node->hasField('field_related_content')
      || $node->get('field_related_content')->isEmpty()
    ) {
      return [];
    }

    $view = Views::getView('entity_reference_for_fields');
    if (!$view) {
      return [];
    }

    // Pass the target ids of the related-content references as a single
    // comma-separated argument; the view's nid contextual filter has
    // break_phrase: true so it accepts a list and returns those nodes.
    $target_ids = array_column(
      $node->get('field_related_content')->getValue(),
      'target_id'
    );
    $argument = implode(',', array_filter($target_ids));

    // buildRenderable() returns a lazy render array; the actual view execution
    // happens at render time and its cache metadata bubbles up automatically.
    $rendered_view = $view->buildRenderable('embed_related_content', [$argument]);

    return [
      '#theme' => 'ys_related_content_block',
      '#related_content__heading' => $this->configuration['heading'] ?? 'Related Content',
      // Unique per render so the molecule's aria-labelledby wiring stays
      // valid if more than one Related Content block ends up on the same
      // page (WCAG 4.1.1).
      '#related_content__heading_id' => Html::getUniqueId('related-content-heading'),
      '#related_content__view' => $rendered_view,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();
    $node = $this->getCurrentNode();
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'url.path']);
  }

  /**
   * Get the current node from either route match or Layout Builder context.
   */
  protected function getCurrentNode() {

    $node = $this->routeMatch->getParameter('node');

    // When removing the contact block when one already exists,
    // it no longer has access to the node object. Therefore, we must load it
    // manually via the ajaxified path.
    if (!$node) {
      $request = $this->requestStack->getCurrentRequest();
      $layoutBuilderPath = $request->getPathInfo();
      preg_match('/(node\.+(\d+))/', $layoutBuilderPath, $matches);
      if (!empty($matches)) {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $node = $nodeStorage->load($matches[2]);
      }
    }

    return $node ?? NULL;
  }

}
