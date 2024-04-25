<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\MetaFieldsManager;
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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The meta fields manager service.
   *
   * @var \Drupal\ys_layouts\MetaFieldsManager
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
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\ys_layouts\MetaFieldsManager $meta_fields_manager
   *   The meta fields manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    DateFormatter $date_formatter,
    EntityTypeManager $entity_type_manager,
    MetaFieldsManager $meta_fields_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('ys_layouts.meta_fields_manager'),
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
    // Event fields.

    $ticketLink = $node->field_ticket_registration_url->first() ? $node->field_ticket_registration_url->first()->getValue()['uri'] : NULL;
    $ticketCost = $node->field_ticket_cost->first() ? $node->field_ticket_cost->first()->getValue()['value'] : NULL;
    $eventDescription = $node->field_event_description->first() ? $node->field_event_description->first()->getValue()['value'] : NULL;
    $eventWebsite = ($node->field_event_cta->first()) ? Url::fromUri($node->field_event_cta->first()->getValue()['uri'])->toString() : NULL;
    $urlTitle = ($node->field_event_cta->first()) ? $node->field_event_cta->first()->getValue()['title'] : NULL;
    $localistImageUrl = ($node->field_localist_event_image_url->first()) ? Url::fromUri($node->field_localist_event_image_url->first()->getValue()['uri'])->toString() : NULL;
    $localistImageAlt = $node->field_localist_event_image_alt->first() ? $node->field_localist_event_image_alt->first()->getValue()['value'] : NULL;

    // Teaser responsive image.
    $teaserMediaRender = [];
    $teaserMediaId = ($node->field_teaser_media->first()) ? $node->field_teaser_media->getValue()[0]['target_id'] : NULL;
    if ($teaserMediaId) {
      /** @var Drupal\media\Entity\Media $teaserMedia */
      if ($teaserMedia = $this->entityTypeManager->getStorage('media')->load($teaserMediaId)) {
        /** @var Drupal\file\FileStorage $fileEntity */
        $fileEntity = $this->entityTypeManager->getStorage('file');
        $teaserImageFileUri = $fileEntity->load($teaserMedia->field_media_image->target_id)->getFileUri();

        $teaserMediaRender = [
          '#type' => 'responsive_image',
          '#responsive_image_style_id' => 'card_featured_3_2',
          '#uri' => $teaserImageFileUri,
          '#attributes' => [
            'alt' => $teaserMedia->get('field_media_image')->first()->get('alt')->getValue(),
          ],
        ];
      }
    }

    // Place info.
    // $place = [];
    // if ($placeRef = $node->field_event_place->first()) {
    //   /** @var \Drupal\taxonomy\Entity\Term $placeInfo */
    //   $placeInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($placeRef->getValue()['target_id']);
    //   if ($placeInfo) {
    //     $place = [
    //       'name' => $placeInfo->getName(),
    //       'address' => $placeInfo->field_address->address_line1 ?? NULL,
    //       'city' => $placeInfo->field_address->locality ?? NULL,
    //       'state' => $placeInfo->field_address->administrative_area ?? NULL,
    //       'postal_code' => $placeInfo->field_address->postal_code ?? NULL,
    //       'country_code' => $placeInfo->field_address->country_code ?? NULL,
    //       'latitude' => $placeInfo->field_latitude->first() ? $placeInfo->field_latitude->first()->getValue()['value'] : NULL,
    //       'longitude' => $placeInfo->field_longitude->first() ? $placeInfo->field_longitude->first()->getValue()['value'] : NULL,
    //     ];
    //   }
    // }

    $eventFieldData = $this->metaFieldsManager->getEventData($node);

    return [
      '#theme' => 'ys_event_meta_block',
      '#event_title__heading' => $eventFieldData['title'],
      '#event_dates' => $eventFieldData['dates'],
      '#ics_url' => $eventFieldData['ics'],
      '#ticket_url' => $ticketLink,
      '#ticket_cost' => $ticketCost,
      //'#place' => $place,
      '#event_types' => $this->getFilterValues($node, 'field_localist_event_type'),
      '#event_audience' => $this->getFilterValues($node, 'field_event_audience'),
      '#event_topics' => $this->getFilterValues($node, 'field_event_topics'),
      '#description' => $eventDescription,
      '#event_meta__cta_primary__href' => $eventWebsite,
      '#event_meta__cta_primary__content' => $urlTitle,
      '#event_experience' => $eventFieldData['experience'],
      '#localist_image_url' => $localistImageUrl,
      '#localist_image_alt' => $localistImageAlt,
      '#teaser_media' => $teaserMediaRender,
    ];
  }

  /**
   * Returns filter values for a given filter field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node object.
   * @param string $filterField
   *   The field machine name.
   *
   * @return array
   *   An array of filter names and URL to visit the taxonomy term view page.
   */
  private function getFilterValues($node, $filterField) {
    $filterValues = [];
    if ($node->$filterField) {
      $values = $node->$filterField->getValue();
      if ($values) {
        foreach ($values as $value) {
          /** @var \Drupal\taxonomy\Entity\Term $typeInfo */
          $typeInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($value['target_id']);
          $filterValues[] = [
            'name' => $typeInfo->getName(),
            'url' => $typeInfo->toUrl()->toString(),
          ];
        }
      }
    }
    return $filterValues;
  }

}
