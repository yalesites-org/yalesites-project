<?php

namespace Drupal\ys_core\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'alt_text_override_default_widget' widget.
 *
 * @FieldWidget(
 *   id = "alt_text_override_default_widget",
 *   label = @Translation("Alt text override"),
 *   field_types = {
 *     "alt_text_override"
 *   }
 * )
 */
class AltTextOverrideDefaultWidget extends WidgetBase {

  /**
   * Define the form for the field type.
   */
  public function formElement(
    FieldItemListInterface $items,
    $delta,
    Array $element,
    Array &$form,
    FormStateInterface $formState
  ) {
    $altText = $items[$delta]->value ?? NULL;
    $decorative = $items[$delta]->decorative ?? 0;

    $element['alt_text_override'] = [
      '#title' => 'Override image alt text',
      '#type' => 'details',
      '#open' => TRUE,
      '#attributes' => [
        'class' => [
          'ys-core--alt-text-override',
        ],
      ],
    ];

    $element['alt_text_override']['decorative'] = [
      '#type' => 'checkbox',
      '#title' => t('Decorative image'),
      '#default_value' => $decorative,
      '#attributes' => [
        'class' => [
          'ys-core--alt-override--decorative',
        ],
      ],
    ];
    $element['alt_text_override']['value'] = [
      '#type' => 'textfield',
      '#title' => t('Alt text'),
      '#default_value' => $altText,
      '#attributes' => [
        'class' => [
          'ys-core--alt-override--alt-text',
        ],
      ],
    ];

    $form['#attached']['library'][] = 'ys_core/alt_text_override';

    return $element;
  }

  /**
   * Get data from sub fields and save into main field table.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      if ($value && array_key_exists('alt_text_override', $value)) {
        $value['value'] = $value['alt_text_override']['value'];
        $value['decorative'] = $value['alt_text_override']['decorative'];
      }
    }
    return parent::massageFormValues($values, $form, $form_state);
  }

}
