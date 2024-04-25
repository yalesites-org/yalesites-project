<?php

namespace Drupal\ys_layouts;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves field data from nodes for meta block and all view modes.
 *
 * Currently, this class is used to retrieve event data as there is a lot of
 * manipulation of event data. Eventually, this class can also be used to
 * retrieve profile, post, or other content type data.
 */
class MetaFieldsManager implements ContainerFactoryPluginInterface {

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
   * Constructs a new EventMetaBlock instance.
   *
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    DateFormatter $date_formatter,
    EntityTypeManager $entity_type_manager,
  ) {
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
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

  /**
   * Gets event all fields.
   */
  public function getEventData($node) {
    if (!($node instanceof NodeInterface)) {
      return [];
    }

    // Event basic fields.
    $icsUrl = $node->field_localist_ics_url->first() ? $node->field_localist_ics_url->first()->getValue()['uri'] : NULL;
    $experience = $this->getFilterValues($node, 'field_localist_event_experience');

    // Dates.
    $dates = $node->field_event_date->getValue();
    if ($dates) {
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
    }

    return [
      'title' => $node->getTitle(),
      'dates' => $dates,
      'ics' => $icsUrl,
      'experience' => $experience,
    ];
  }

}
