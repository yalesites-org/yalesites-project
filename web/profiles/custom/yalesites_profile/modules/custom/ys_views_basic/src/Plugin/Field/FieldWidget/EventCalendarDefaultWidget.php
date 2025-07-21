<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'event_calendar_default' widget.
 *
 * @FieldWidget(
 *   id = "event_calendar_default_widget",
 *   label = @Translation("Event calendar default widget"),
 *   field_types = {
 *     "event_calendar_basic_params"
 *   }
 * )
 */
class EventCalendarDefaultWidget extends ViewsBasicDefaultWidget {

  /**
   * {@inheritdoc}
   *
   * Add event calendar specific options.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $formState) {
    $element['hide_add_to_calendar'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Add to Calendar link'),
      '#default_value' => !empty($items[$delta]->event_field_options['hide_add_to_calendar']),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $hide_add_to_calendar = !empty($form['hide_add_to_calendar']['#value']);
      $paramData = [
        "view_mode" => "calendar",
        "filters" => [
          "types" => ["event"],
          "event_time_period" => "future",
        ],
        "event_field_options" => [
          "hide_add_to_calendar" => $hide_add_to_calendar,
        ],
        "operator" => "+",
        "sort_by" => "field_event_date:DESC",
        "display" => "all",
        "limit" => 0,
        "offset" => 0,
      ];
      $value['params'] = json_encode($paramData);
    }

    return $values;
  }

}
