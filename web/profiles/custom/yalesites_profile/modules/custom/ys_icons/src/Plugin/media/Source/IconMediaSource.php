<?php

namespace Drupal\ys_icons\Plugin\media\Source;

use Drupal\media\MediaSourceBase;
use Drupal\media\MediaInterface;

/**
 * Icon media source.
 *
 * @MediaSource(
 *   id = "ys_icon_source",
 *   label = @Translation("Icon"),
 *   description = @Translation("Use for FontAwesome icon references."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "icon.png"
 * )
 */
class IconMediaSource extends MediaSourceBase {

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      'fontawesome_name' => $this->t('FontAwesome name'),
      'icon_title' => $this->t('Icon title'),
      'icon_description' => $this->t('Icon description'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    switch ($attribute_name) {
      case 'fontawesome_name':
        return $media->get('field_fontawesome_name')->value;

      case 'icon_title':
        return $media->get('field_icon_title')->value;

      case 'icon_description':
        return $media->get('field_icon_description')->value;

      case 'default_name':
        return $media->get('field_icon_title')->value ?: $media->get('name')->value;

      case 'thumbnail_uri':
        $icon_name = $media->get('field_fontawesome_name')->value;
        if ($icon_name) {
          return '/themes/contrib/atomic/node_modules/@yalesites-org/component-library-twig/dist/icons.svg#' . $icon_name;
        }
        return NULL;

      default:
        return parent::getMetadata($media, $attribute_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceFieldName() {
    return 'field_fontawesome_name';
  }

}
