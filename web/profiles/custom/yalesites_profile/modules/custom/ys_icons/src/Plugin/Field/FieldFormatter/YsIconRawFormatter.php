<?php

namespace Drupal\ys_icons\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\media\MediaInterface;

/**
 * Plugin implementation of the 'ys_icon_raw' formatter.
 *
 * @FieldFormatter(
 *   id = "ys_icon_raw",
 *   label = @Translation("Icon (Raw Data)"),
 *   description = @Translation("Outputs raw icon data for use in Twig templates."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class YsIconRawFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $media) {
      if ($media instanceof MediaInterface && $media->bundle() === 'icon') {
        $elements[$delta] = [
          '#cache' => [
            'tags' => $media->getCacheTags(),
          ],
          '#fontawesome_name' => $media->get('field_fontawesome_name')->value,
          '#title' => $media->get('field_icon_title')->value,
          '#description' => $media->get('field_icon_description')->value,
          '#name' => $media->label(),
        ];
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable($field_definition) {
    // Only show this formatter for entity reference fields targeting media entities.
    $target_type = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
    return $target_type === 'media';
  }

}
