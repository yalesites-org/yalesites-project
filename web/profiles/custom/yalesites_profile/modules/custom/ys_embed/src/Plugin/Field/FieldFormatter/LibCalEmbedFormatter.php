<?php

namespace Drupal\ys_embed\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'libcal_embed' formatter.
 *
 * @FieldFormatter(
 *   id = "libcal_embed",
 *   label = @Translation("LibCal Embed"),
 *   field_types = {
 *     "embed"
 *   }
 * )
 */
class LibCalEmbedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $embed_code = $item->get('input')->getValue();
      \Drupal::logger('ys_embed')->notice('LibCalEmbedFormatter: Processing embed code: @code.', ['@code' => $embed_code]);

      // Check for weekly grid embed.
      if (strpos($embed_code, 'hours_grid.js') !== FALSE || strpos($embed_code, 'LibCalWeeklyGrid') !== FALSE) {
        \Drupal::logger('ys_embed')->notice('LibCalEmbedFormatter: Detected weekly grid embed.');
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['embed-libcal-weekly'],
            'data-embed-code' => $embed_code,
            'data-processed' => 'true',
          ],
        ];
      }
      // Check for daily hours embed.
      elseif (strpos($embed_code, 'hours_today.js') !== FALSE || strpos($embed_code, 'LibCalTodayHours') !== FALSE) {
        \Drupal::logger('ys_embed')->notice('LibCalEmbedFormatter: Detected daily hours embed.');
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['embed-libcal'],
            'data-embed-code' => $embed_code,
            'data-processed' => 'true',
          ],
        ];
      }
      else {
        \Drupal::logger('ys_embed')->notice('LibCalEmbedFormatter: Unknown embed type.');
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['embed-libcal-unknown'],
            'data-embed-code' => $embed_code,
            'data-processed' => 'true',
          ],
        ];
      }
    }

    return $elements;
  }

} 