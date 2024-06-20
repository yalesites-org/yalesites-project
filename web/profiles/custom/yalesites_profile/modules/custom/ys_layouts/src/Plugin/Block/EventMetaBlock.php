<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_localist\MetaFieldsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Block for event meta data that appears above events.
 *
 * @Block(
 *   id = "event_meta_block",
 *   admin_label = @Translation("Event Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class EventMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The meta fields manager service.
   *
   * @var \Drupal\ys_localist\MetaFieldsManager
   */
  protected $metaFieldsManager;

  /**
   * Constructs a new EventMetaBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\ys_localist\MetaFieldsManager $meta_fields_manager
   *   The meta fields manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    MetaFieldsManager $meta_fields_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
    $this->metaFieldsManager = $meta_fields_manager;
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
      $container->get('ys_localist.meta_fields_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->routeMatch->getParameter('node');
    if (!($node instanceof NodeInterface)) {
      return [];
    }

    // Gets all event field data.
    $eventFieldData = $this->metaFieldsManager->getEventData($node);

    return [
      '#theme' => 'ys_event_meta_block',
      '#event_title__heading' => $eventFieldData['title'],
      '#event_dates' => $eventFieldData['dates'],
      '#ics_url' => $eventFieldData['ics'],
      '#canonical_url' => $eventFieldData['canonical_url'],
      '#ticket_url' => $eventFieldData['ticket_url'],
      '#ticket_cost' => $eventFieldData['ticket_cost'],
      '#place' => $eventFieldData['place_info'],
      '#event_types' => $eventFieldData['event_types'],
      '#event_audience' => $eventFieldData['event_audience'],
      '#event_topics' => $eventFieldData['event_topics'],
      '#description' => $eventFieldData['description'],
      '#event_meta__cta_primary__href' => $eventFieldData['external_website_url'],
      '#event_meta__cta_primary__content' => $eventFieldData['external_website_title'],
      '#event_experience' => $eventFieldData['experience'],
      '#localist_image_url' => $eventFieldData['localist_image_url'],
      '#localist_image_alt' => $eventFieldData['localist_image_alt'],
      '#teaser_media' => $eventFieldData['teaser_media'],
      '#has_register' => $eventFieldData['has_register'],
      '#cost_button_text' => $eventFieldData['cost_button_text'],
      '#localist_url' => $eventFieldData['localist_url'],
      '#stream_url' => $eventFieldData['stream_url'],
      '#stream_embed_code' => $eventFieldData['stream_embed_code'],
      '#is_conference' => $eventFieldData['is_conference'],
      '#nid' => $eventFieldData['id'],
    ];
  }

}
