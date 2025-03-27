<?php

namespace Drupal\ys_embed\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'libcal_embed' formatter.
 *
 * @FieldFormatter(
 *   id = "libcal_embed",
 *   label = @Translation("LibCal Embed"),
 *   field_types = {
 *     "text",
 *     "text_long",
 *   },
 * )
 */
class LibCalEmbedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['embed-libcal'],
        ],
        '#value' => $item->value,
      ];
    }

    return $elements;
  }

}
