<?php

namespace Drupal\ys_embed\Plugin\media\Source;

use Drupal\media\MediaSourceBase;
use Drupal\media\MediaSourceFieldConstraintsInterface;
use Drupal\media\MediaInterface;

/**
 * Provides a media source plugin for Qualtrics forms.
 *
 * @MediaSource(
 *   id = "embed",
 *   label = @Translation("Embed"),
 *   description = @Translation("Embed a...."),
 *   allowed_field_types = {"embed"},
 *   default_thumbnail_filename = "generic.png",
 * )
 */
class Embed extends MediaSourceBase implements MediaSourceFieldConstraintsInterface {

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      'embed_code' => $this->t('embed_code'),
      'title' => $this->t('Title'),
      'width' => $this->t('Width'),
      'height' => $this->t('Height'),
      'thumbnail_uri' => $this->t('Thumbnail local URI'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $name) {
    switch ($name) {
      case 'thumbnail_uri':
        return 'media-icons/generic/qualtrics.png';
    }
    return parent::getMetadata($media, $name);
  }

  /**
   * {@inheritDoc}
   */
  public function getSourceFieldConstraints() {
    return [
      'embed' => [],
    ];
  }

}
