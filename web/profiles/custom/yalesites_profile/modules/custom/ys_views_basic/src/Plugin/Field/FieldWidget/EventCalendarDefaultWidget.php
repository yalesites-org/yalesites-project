<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\WidgetBase;

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
class EventCalendarDefaultWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   *
   * Add event calendar specific options.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $formState) {
    // Value will be set in massageFormValues().
    $element['params'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $paramData = [];
      $value['params'] = json_encode($paramData);
    }

    return $values;
  }

}
