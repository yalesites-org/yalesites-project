<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
class EventCalendarDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The Events Calendar service.
   *
   * @var \Drupal\ys_views_basic\Service\EventsCalendarInterface
   */
  protected EventsCalendarInterface $eventsCalendar;

  /**
   * Constructs an views basic default formatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   The views basic manager service.
   * @param \Drupal\ys_views_basic\Service\EventsCalendarInterface $eventsCalendar
   *   The Events Calendar service.
   */
  public function __construct(
    string $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    EventsCalendarInterface $eventsCalendar,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
    );
    $this->eventsCalendar = $eventsCalendar;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('ys_views_basic.events_calendar')
    );
  }

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
