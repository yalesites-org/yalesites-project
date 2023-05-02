<?php

namespace Drupal\ys_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'alt_text_override_default_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "alt_text_override_default_formatter",
 *   module = "custom_module_name",
 *   label = @Translation("Alt text override"),
 *   field_types = {
 *     "alt_text_override"
 *   }
 * )
 */
class AltTextOverrideDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $item->value,
      ];
    }

    return $elements;
  }

}
