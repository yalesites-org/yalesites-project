<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_views_basic\Service\EventsCalendarInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'views_basic_default' formatter.
 *
 * @FieldFormatter(
 *   id = "views_basic_default_formatter",
 *   label = @Translation("Views Basic View"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class ViewsBasicDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected ViewsBasicManager $viewsBasicManager;

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
   *   Any third party settings.
   * @param \Drupal\ys_views_basic\ViewsBasicManager $viewsBasicManager
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
    ViewsBasicManager $viewsBasicManager,
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
    $this->viewsBasicManager = $viewsBasicManager;
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
      $container->get('ys_views_basic.views_basic_manager'),
      $container->get('ys_views_basic.events_calendar')
    );
  }

  /**
   * Define how the field type is showed.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {

    $elements = [];

    foreach ($items as $delta => $item) {
      // Get decoded parameters.
      $paramsDecoded = json_decode($item->getValue()['params'], TRUE);

      if ($paramsDecoded['filters']['types'][0] === 'event' && $paramsDecoded['view_mode'] === 'calendar') {
        // Calculate the remaining time until the end of the current month.
        $now = new \DateTime();
        $end_of_month = new \DateTime('last day of this month 23:59:59');
        $remaining_time_in_seconds = $end_of_month->getTimestamp() - $now->getTimestamp();

        $events_calendar = $this->eventsCalendar
          ->getCalendar(date('m'), date('Y'));

        $elements[$delta] = [
          '#theme' => 'views_basic_events_calendar',
          '#month_data' => $events_calendar,
          '#cache' => [
            'tags' => ['node_list:event'],
            // Set max-age to the remaining time until the end of the month.
            'max-age' => $remaining_time_in_seconds,
            'contexts' => ['timezone'],
          ],
        ];
      }
      else {
        $view = $this->viewsBasicManager->getView('rendered', $item->getValue()['params']);
        // Extract exposed widgets from the view.
        // The view might be NULL or a ViewExecutable object.
        $exposedWidgets = NULL;
        if ($view) {
          // Check if exposed_widgets exists on the view object.
          if (is_object($view) && isset($view->exposed_widgets)) {
            $exposedWidgets = $view->exposed_widgets;
          }
          // Handle case where view might be a render array (though getView returns ViewExecutable).
          elseif (is_array($view) && isset($view['#view']) && isset($view['#view']->exposed_widgets)) {
            $exposedWidgets = $view['#view']->exposed_widgets;
          }
        }

        $elements[$delta] = [
          '#theme' => 'views_basic_formatter_default',
          '#view' => $view,
          // Extract exposed filters from the view and place them separately.
          // This is necessary because we are conditionally displaying
          // specific exposed filters based on field configuration.
          // By placing the exposed filters outside of the view rendering
          // context, we ensure that they do not get re-rendered
          // when AJAX operations are performed on the view,
          // allowing for better control over which filters are displayed
          // and maintaining the expected user interface behavior.
          '#exposed' => $exposedWidgets,
        ];
      }
    }

    return $elements;
  }

}
