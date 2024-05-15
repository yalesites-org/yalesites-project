<?php

namespace Drupal\ys_embed\Plugin\media\Source;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaSourceFieldConstraintsInterface;
use Drupal\ys_embed\Plugin\EmbedSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a media source plugin for embedding external content.
 *
 * @MediaSource(
 *   id = "embed",
 *   label = @Translation("Embed source"),
 *   description = @Translation("Used to embed external content"),
 *   allowed_field_types = {"embed"},
 *   default_thumbnail_filename = "generic.png",
 *   forms = {
 *     "media_library_add" = "\Drupal\ys_embed\Form\EmbedMediaLibraryAddForm",
 *   }
 * )
 */
class Embed extends MediaSourceBase implements MediaSourceFieldConstraintsInterface {

  /**
   * The embed source plugin manager service.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSourceManager
   */
  protected $embedManager;

  /**
   * Constructs a new Embed media source instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $field_type_manager
   *   The field type plugin manager service.
   * @param \Drupal\ys_embed\Plugin\EmbedSourceManager $embed_manager
   *   The EmbedSource management service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    ConfigFactoryInterface $config_factory,
    FieldTypePluginManagerInterface $field_type_manager,
    EmbedSourceManager $embed_manager,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $entity_field_manager,
      $field_type_manager,
      $config_factory
    );
    $this->embedManager = $embed_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('plugin.manager.embed_source'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      'embed_code' => $this->t('Embed Code'),
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
    // Set a unique media gallery thumbnail per EmbedSource.
    if ($name === 'thumbnail_uri') {
      return $this->getDefaultThumbnailUri($media);
    }
    if ($name === 'default_name') {
      return $this->getDefaultName($media);
    }
    return parent::getMetadata($media, $name);
  }

  /**
   * Find the default media library thumbnail for an EmbedSource plugin.
   *
   * @param \Drupal\media\MediaInterface $media
   *   A media entity created with this media source.
   *
   * @return string
   *   The URI for a thumbnail image.
   */
  protected function getDefaultThumbnailUri(MediaInterface $media): string {
    $input = $this->getSourceFieldValue($media);
    $source = $this->embedManager->loadPluginByCode($input);
    return $source->getDefaultThumbnailUri();
  }

  /**
   * Find the default name for a media item.
   *
   * Media using the EmbedSource plugin uses the 'embed' field. This field has
   * a 'title' property. This method returns the value of this property. The
   * title may be used to automatically set the media 'name' value.
   *
   * @param \Drupal\media\MediaInterface $media
   *   A media entity created with this media source.
   *
   * @return string
   *   The title property from the media's 'embed' field.
   */
  protected function getDefaultName(MediaInterface $media) {
    $source_field = $this->configuration['source_field'];
    if (empty($source_field)) {
      throw new \RuntimeException('Source field for media source is not defined.');
    }

    $items = $media->get($source_field);
    if ($items->isEmpty()) {
      return NULL;
    }

    /** @var \Drupal\ys_embed\Plugin\Field\FieldType\Embed $field_item */
    $field_item = $items->first();
    return $field_item->get('title')->getValue();
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
