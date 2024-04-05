<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
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
   * Constructs a new BookNavigationBlock instance.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match,
    DateFormatter $date_formatter,
    EntityTypeManager $entity_type_manager,
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
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
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Calculates if an event is set to all day.
   *
   * Code copied from contrib module smart_date/src/SmartDateTrait.php because
   * with version 3.6 of smart_date and PHP 8.1, calling static traits
   * in this way is deprecated. Patches to fix failed to apply.
   */
  private function isAllDay($start_ts, $end_ts, $timezone = NULL) {
    if ($timezone) {
      if ($timezone instanceof \DateTimeZone) {
        // If provided as an object, convert to a string.
        $timezone = $timezone->getName();
      }
      // Apply a specific timezone provided.
      $default_tz = date_default_timezone_get();
      date_default_timezone_set($timezone);
    }
    // Format timestamps to predictable format for comparison.
    $temp_start = date('H:i', $start_ts);
    $temp_end = date('H:i', $end_ts);
    if ($timezone) {
      // Revert to previous timezone.
      date_default_timezone_set($default_tz);
    }
    if ($temp_start == '00:00' && $temp_end == '23:59') {
      return TRUE;
    }
    return FALSE;
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
    $title = $node->getTitle();
    $icsUrl = $node->field_localist_ics_url->first() ? $node->field_localist_ics_url->first()->getValue()['uri'] : NULL;
    $ticketLink = $node->field_ticket_registration_url->first() ? $node->field_ticket_registration_url->first()->getValue()['uri'] : NULL;
    $ticketCost = $node->field_ticket_cost->first() ? $node->field_ticket_cost->first()->getValue()['value'] : NULL;
    $eventDescription = $node->field_event_description->first() ? $node->field_event_description->first()->getValue()['value'] : NULL;
    $eventWebsite = ($node->field_event_cta->first()) ? Url::fromUri($node->field_event_cta->first()->getValue()['uri'])->toString() : NULL;
    $urlTitle = ($node->field_event_cta->first()) ? $node->field_event_cta->first()->getValue()['title'] : NULL;

    // Dates.
    $dates = $node->field_event_date->getValue();
    foreach ($dates as $key => $date) {
      $dates[$key]['formatted_start_date'] = $this->dateFormatter->format($date['value'], 'event_date_only');
      $dates[$key]['formatted_start_time'] = $this->dateFormatter->format($date['value'], 'event_time_only');
      $dates[$key]['formatted_end_date'] = $this->dateFormatter->format($date['end_value'], 'event_date_only');
      $dates[$key]['formatted_end_time'] = $this->dateFormatter->format($date['end_value'], 'event_time_only');
      $dates[$key]['is_all_day'] = $this->isAllDay($date['value'], $date['end_value']);
      // Remove older dates.
      if ($date['end_value'] < time()) {
        unset($dates[$key]);
      }
    }
    // Sort dates - first date is next upcoming date.
    asort($dates);

    // Place info.
    $place = [];
    if ($placeRef = $node->field_event_place->first()) {
      /** @var \Drupal\taxonomy\Entity\Term $placeInfo */
      $placeInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($placeRef->getValue()['target_id']);

      $place = [
        'name' => $placeInfo->getName(),
        'address' => $placeInfo->field_address->address_line1 ?? NULL,
        'city' => $placeInfo->field_address->locality ?? NULL,
        'state' => $placeInfo->field_address->administrative_area ?? NULL,
        'postal_code' => $placeInfo->field_address->postal_code ?? NULL,
        'country_code' => $placeInfo->field_address->country_code ?? NULL,
        'latitude' => $placeInfo->field_latitude->first() ? $placeInfo->field_latitude->first()->getValue()['value'] : NULL,
        'longitude' => $placeInfo->field_longitude->first() ? $placeInfo->field_longitude->first()->getValue()['value'] : NULL,
      ];
    }

    // Event types.
    $eventTypes = [];
    if ($node->field_localist_event_type) {
      $types = $node->field_localist_event_type->getValue();
      foreach ($types as $type) {
        /** @var \Drupal\taxonomy\Entity\Term $typeInfo */
        $typeInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($type['target_id']);
        $eventTypes[] = [
          'name' => $typeInfo->getName(),
          'url' => $typeInfo->toUrl()->toString(),
        ];
      }

    }

    // Event experience.
    $eventExperienceId = $node->field_localist_event_experience->first() ? $node->field_localist_event_experience->first()->getValue()['target_id'] : NULL;
    /** @var \Drupal\taxonomy\Entity\Term $eventExperienceInfo */
    $eventExperienceInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($eventExperienceId);
    $eventExperienceName = $eventExperienceInfo ? $eventExperienceInfo->getName() : NULL;

    return [
      '#theme' => 'ys_event_meta_block',
      '#event_title__heading' => $title,
      '#event_dates' => $dates,
      '#ics_url' => $icsUrl,
      '#ticket_url' => $ticketLink,
      '#ticket_cost' => $ticketCost,
      '#place' => $place,
      '#event_types' => $eventTypes,
      '#description' => $eventDescription,
      '#event_meta__cta_primary__href' => $eventWebsite,
      '#event_meta__cta_primary__content' => $urlTitle,
      '#event_experience' => $eventExperienceName,
    ];
  }

}
