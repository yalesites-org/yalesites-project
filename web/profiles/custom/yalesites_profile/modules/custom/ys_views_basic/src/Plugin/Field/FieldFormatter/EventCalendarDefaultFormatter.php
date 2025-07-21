<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Plugin implementation of the 'event_calendar_default' formatter.
 *
 * @FieldFormatter(
 *   id = "event_calendar_default_formatter",
 *   label = @Translation("Event Calendar View"),
 *   field_types = {
 *     "event_calendar_basic_params"
 *   }
 * )
 */
class EventCalendarDefaultFormatter extends ViewsBasicDefaultFormatter implements ContainerFactoryPluginInterface {

  /**
   * Define how the calendar is displayed.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {

      // Always use the calendar service regardless of params.
      $now = new \DateTime();
      $end_of_month = new \DateTime('last day of this month 23:59:59');
      $remaining_time_in_seconds = $end_of_month->getTimestamp() - $now->getTimestamp();

      $events_calendar = $this->eventsCalendar->getCalendar(date('m'), date('Y'));

      $elements[$delta] = [
        '#theme' => 'views_basic_events_calendar',
        '#month_data' => $events_calendar,
        '#cache' => [
          'tags' => ['node_list:event'],
          'max-age' => $remaining_time_in_seconds,
          'contexts' => ['timezone'],
        ],
      ];
    }
    return $elements;
  }

}
