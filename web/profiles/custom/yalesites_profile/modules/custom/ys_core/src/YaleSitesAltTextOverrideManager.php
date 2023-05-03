<?php

namespace Drupal\ys_core;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\media\Entity\Media;

/**
 * Service for managing custom breadcrumbs for YaleSites.
 */
class YaleSitesAltTextOverrideManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The media entity class.
   *
   * @var \Drupal\media\Entity\Media
   */
  protected $mediaEntity;

  /**
   * Constructs a new YaleSitesBreadcrumbBlock object.
   *
   * @param \Drupal\media\Entity\Media $media_entity
   *   The breadcrumb manager.
   */
  // public function __construct(Media $media_entity) {
  //   $this->mediaEntity = $media_entity;
  // }

  public function tester() {
    $media = Media::load(1);

    return $media;
  }

}
