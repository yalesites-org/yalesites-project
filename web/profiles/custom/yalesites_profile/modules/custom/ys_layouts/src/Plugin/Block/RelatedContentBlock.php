<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('current_route_match'),
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

    $form['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Heading'),
      '#description' => $this->t('Heading shown above the related content list.'),
      '#default_value' => $config['heading'] ?? 'Related Content',
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
    $node = $this->routeMatch->getParameter('node');

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
    $node = $this->routeMatch->getParameter('node');
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

}
