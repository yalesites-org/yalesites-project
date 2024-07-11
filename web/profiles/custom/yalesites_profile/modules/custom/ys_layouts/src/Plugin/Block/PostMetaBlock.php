<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Traits\UuidTitleTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Block for post meta data that appears above posts.
 *
 * @Block(
 *   id = "post_meta_block",
 *   admin_label = @Translation("Post Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class PostMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use UuidTitleTrait;

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
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new PostMetaBlock object.
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
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, TitleResolver $title_resolver, RequestStack $request_stack, DateFormatter $date_formatter, $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
    $this->requestStack = $request_stack;
    $this->dateFormatter = $date_formatter;
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
      $container->get('title_resolver'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $route = $this->routeMatch->getRouteObject();
    $request = $this->requestStack->getCurrentRequest();

    $title = '';
    $node = NULL;
    $title = NULL;
    $author = NULL;
    $publishDate = NULL;
    $dateFormatted = NULL;

    if ($route) {
      $title = $this->titleResolver->getTitle($request, $route);
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->getEntityNode($title, $this->entityTypeManager, $request);
    }

    if (!($node instanceof NodeInterface)) {
      return [];
    }
    else {
      $title = $node->getTitle();
    }

    if ($route) {
      // Post fields.
      $author = ($node->field_author->first()) ? $node->field_author->first()->getValue()['value'] : NULL;
      $publishDate = strtotime($node->field_publish_date->first()->getValue()['value']);
      $dateFormatted = $this->dateFormatter->format($publishDate, '', 'c');
    }

    return [
      '#theme' => 'ys_post_meta_block',
      '#label' => $title,
      '#author' => $author,
      '#date_formatted' => $dateFormatted,
    ];
  }

}
