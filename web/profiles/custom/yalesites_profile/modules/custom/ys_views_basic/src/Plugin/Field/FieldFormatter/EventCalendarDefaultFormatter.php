<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Drupal\ys_views_basic\ViewsBasicManager;

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
class EventCalendarDefaultFormatter extends ViewsBasicDefaultFormatter {
  /**
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    ViewsBasicManager $viewsBasicManager,
    EventsCalendarInterface $eventsCalendar,
    CurrentRouteMatch $currentRouteMatch
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
      $viewsBasicManager,
      $eventsCalendar
    );
    $this->currentRouteMatch = $currentRouteMatch;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('ys_views_basic.views_basic_manager'),
      $container->get('ys_views_basic.events_calendar'),
      $container->get('current_route_match')
    );
  }

  /**
   * Define how the calendar is displayed.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      $paramsDecoded = json_decode($item->getValue()['params'], TRUE);

      // Unique wrapper for AJAX replacement.
      $wrapper_id = 'event-calendar-filter-wrapper-' . uniqid();

      $form = \Drupal::formBuilder()->getForm(
        'Drupal\\ys_views_basic\\Form\\EventCalendarFilterForm',
        $item->getValue()['params'],
        $wrapper_id
      );

      // Build the filters array from paramsDecoded.
      $filters = [
        'category_included_terms' => $paramsDecoded['category_included_terms'] ?? [],
        'audience_included_terms' => $paramsDecoded['audience_included_terms'] ?? [],
        'custom_vocab_included_terms' => $paramsDecoded['custom_vocab_included_terms'] ?? [],
        'terms_include' => $paramsDecoded['terms_include'] ?? [],
        'terms_exclude' => $paramsDecoded['terms_exclude'] ?? [],
        'term_operator' => $paramsDecoded['term_operator'] ?? '+',
      ];

      // Always use the calendar service regardless of params (filtered in AJAX callback later).
      $now = new \DateTime();
      $end_of_month = new \DateTime('last day of this month 23:59:59');
      $remaining_time_in_seconds = $end_of_month->getTimestamp() - $now->getTimestamp();
      $events_calendar = $this->eventsCalendar->getCalendar(date('m'), date('Y'), $filters);

      $elements[$delta] = [
        'filter_form' => $form,
      ];
    }
    return $elements;
  }

}
