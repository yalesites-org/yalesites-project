<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Drupal\Core\Form\FormBuilderInterface;

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
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

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
    CurrentRouteMatch $currentRouteMatch,
    FormBuilderInterface $formBuilder,
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
    $this->formBuilder = $formBuilder;
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
      $container->get('ys_views_basic.views_basic_manager'),
      $container->get('ys_views_basic.events_calendar'),
      $container->get('current_route_match'),
      $container->get('form_builder'),
    );
  }

  /**
   * Define how the calendar is displayed.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      // Unique wrapper for AJAX replacement.
      $wrapper_id = 'event-calendar-filter-wrapper-' . uniqid();

      // Get the form for the exposed filter and calendar.
      $form = $this->formBuilder->getForm(
        'Drupal\\ys_views_basic\\Form\\EventCalendarFilterForm',
        $item->getValue()['params'],
        $wrapper_id
      );

      // Add the form to the elements array.
      $elements[$delta] = [
        'filter_form' => $form,
      ];
    }
    return $elements;
  }

}
