<?php

namespace Drupal\ys_embed\Plugin\media\Source;

use Drupal\media\MediaSourceBase;
use Drupal\media\MediaSourceFieldConstraintsInterface;

/**
 * Provides a media source plugin for Qualtrics forms.
 *
 * @MediaSource(
 *   id = "qualtrics",
 *   label = @Translation("Qualtrics"),
 *   description = @Translation("Embed a Qualtrics form."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "qualtrics.png",
 *   forms = {
 *     "media_library_add" = "\Drupal\ys_embed\Form\QualtricsMediaLibraryAddForm",
 *   }
 * )
 */
class Qualtrics extends MediaSourceBase implements MediaSourceFieldConstraintsInterface {

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      'width' => $this->t('Width'),
      'height' => $this->t('Height'),
      'url' => $this->t('URL'),
      'title' => $this->t('Title'),
      'url' => $this->t('URL'),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getSourceFieldConstraints() {
    return [
      'qualtrics' => [],
    ];
  }

}
