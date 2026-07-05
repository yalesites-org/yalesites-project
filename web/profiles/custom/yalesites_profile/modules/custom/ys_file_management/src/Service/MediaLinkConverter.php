<?php

namespace Drupal\ys_file_management\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Rewrites legacy "media" links in rich text into direct file links.
 *
 * @see \Drupal\ys_file_management\Service\MediaLinkConverterInterface
 */
class MediaLinkConverter implements MediaLinkConverterInterface {

  /**
   * The logger channel name for this module.
   */
  private const LOGGER_CHANNEL = 'ys_file_management';

  /**
   * Formatted-text field types that may hold Linkit markup.
   */
  private const TEXT_FIELD_TYPES = ['text_long', 'text_with_summary', 'text'];

  /**
   * Substring identifying an anchor authored as a media link.
   */
  private const MEDIA_LINK_MARKER = 'data-entity-type="media"';

  /**
   * Resolved media-UUID to file lookups, memoized for the run.
   *
   * The same document is often linked from many pages, so caching avoids
   * re-querying for a UUID already resolved.
   *
   * @var array<string, \Drupal\file\FileInterface|null>
   */
  protected array $mediaFileCache = [];

  /**
   * Constructs a MediaLinkConverter service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository, used to load media by UUID.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityRepositoryInterface $entityRepository,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convertAllContent(): array {
    $stats = [
      'entities_updated' => 0,
      'entities_failed' => 0,
      'links_converted' => 0,
      'links_skipped' => 0,
    ];

    foreach ($this->entityFieldManager->getFieldMap() as $entity_type_id => $fields) {
      $text_fields = [];
      foreach ($fields as $field_name => $info) {
        if (in_array($info['type'], self::TEXT_FIELD_TYPES, TRUE)) {
          $text_fields[$field_name] = $info['type'];
        }
      }
      if (!$text_fields) {
        continue;
      }

      try {
        $storage = $this->entityTypeManager->getStorage($entity_type_id);
      }
      catch (\Exception $e) {
        continue;
      }
      if (!$storage instanceof ContentEntityStorageInterface) {
        continue;
      }

      foreach ($this->loadCandidates($storage, $entity_type_id, $text_fields) as $entity) {
        if (!$entity instanceof FieldableEntityInterface) {
          continue;
        }

        // Only the default revision is scanned/saved; pending moderation drafts
        // and other non-default revisions keep their original markup.
        $result = $this->convertEntity($entity, $text_fields);
        $stats['links_skipped'] += $result['skipped'];
        if (!$result['changed']) {
          continue;
        }

        // Update in place rather than spawning a revision per entity. Guard on
        // the entity type actually being revisionable — ContentEntityBase
        // implements RevisionableInterface even for non-revisionable types.
        if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
          $entity->setNewRevision(FALSE);
        }

        // Isolate each save so a single bad entity (e.g. a validation
        // constraint added since it was authored) cannot abort the whole
        // bulk run; only count links that actually persisted.
        try {
          $entity->save();
          $stats['links_converted'] += $result['converted'];
          $stats['entities_updated']++;
        }
        catch (\Exception $e) {
          $stats['entities_failed']++;
          $this->getLogger()->warning('Could not save @type @id while converting media links: @message', [
            '@type' => $entity_type_id,
            '@id' => $entity->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    return $stats;
  }

  /**
   * Rewrites the media links in one entity's text fields (in memory).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to process.
   * @param array $text_fields
   *   Map of field name to field type for the text fields to scan.
   *
   * @return array
   *   Result with keys 'changed' (bool — whether any value was rewritten),
   *   'converted' and 'skipped' (link counts for this entity).
   */
  protected function convertEntity(FieldableEntityInterface $entity, array $text_fields): array {
    $changed = FALSE;
    $converted = 0;
    $skipped = 0;
    foreach ($text_fields as $field_name => $type) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      foreach ($entity->get($field_name) as $item) {
        foreach ($this->textProperties($type) as $property) {
          $value = (string) ($item->{$property} ?? '');
          if ($value === '') {
            continue;
          }
          $result = $this->convertMarkup($value);
          $converted += $result['converted'];
          $skipped += $result['skipped'];
          if ($result['converted'] > 0) {
            $item->{$property} = $result['html'];
            $changed = TRUE;
          }
        }
      }
    }

    return ['changed' => $changed, 'converted' => $converted, 'skipped' => $skipped];
  }

  /**
   * {@inheritdoc}
   */
  public function convertMarkup(string $html): array {
    $converted = 0;
    $skipped = 0;

    // Cheap guard mirroring the Linkit filter: skip strings with no entity
    // links at all so untouched content is never round-tripped through the DOM.
    if (strpos($html, 'data-entity-type') === FALSE) {
      return ['html' => $html, 'converted' => 0, 'skipped' => 0];
    }

    $dom = Html::load($html);
    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//a[@data-entity-type="media" and @data-entity-uuid]') as $anchor) {
      /** @var \DOMElement $anchor */
      $uuid = $anchor->getAttribute('data-entity-uuid');
      if ($uuid === '') {
        continue;
      }

      try {
        $file = $this->resolveMediaFile($uuid);
        if (!$file instanceof FileInterface) {
          // No downloadable file (e.g. remote video/embed) or missing media —
          // leave the link untouched.
          $skipped++;
          continue;
        }

        $anchor->setAttribute('data-entity-type', 'file');
        $anchor->setAttribute('data-entity-uuid', $file->uuid());
        $anchor->setAttribute('data-entity-substitution', 'file');
        $anchor->setAttribute('href', $this->fileUrlGenerator->generateString($file->getFileUri()));
        $converted++;
      }
      catch (\Exception $e) {
        $skipped++;
        $this->getLogger()->warning('Could not convert media link @uuid to a file link: @message', [
          '@uuid' => $uuid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Preserve the original markup byte-for-byte when nothing was rewritten.
    if ($converted === 0) {
      return ['html' => $html, 'converted' => 0, 'skipped' => $skipped];
    }

    return ['html' => Html::serialize($dom), 'converted' => $converted, 'skipped' => $skipped];
  }

  /**
   * Loads the content entities whose text fields contain a media link.
   *
   * @param \Drupal\Core\Entity\ContentEntityStorageInterface $storage
   *   The storage handler for the entity type.
   * @param string $entity_type_id
   *   The entity type ID (for logging).
   * @param array $text_fields
   *   Map of field name to field type for the text fields to scan.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The candidate entities, keyed by ID.
   */
  protected function loadCandidates(ContentEntityStorageInterface $storage, string $entity_type_id, array $text_fields): array {
    $ids = [];
    foreach ($text_fields as $field_name => $type) {
      try {
        $query = $storage->getQuery()->accessCheck(FALSE);
        $group = $query->orConditionGroup()
          ->condition($field_name . '.value', '%' . self::MEDIA_LINK_MARKER . '%', 'LIKE');
        if ($type === 'text_with_summary') {
          $group->condition($field_name . '.summary', '%' . self::MEDIA_LINK_MARKER . '%', 'LIKE');
        }
        $query->condition($group);
        foreach ($query->execute() as $id) {
          $ids[$id] = $id;
        }
      }
      catch (\Exception $e) {
        $this->getLogger()->warning('Could not scan @type field @field for media links: @message', [
          '@type' => $entity_type_id,
          '@field' => $field_name,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Resolves a media UUID to its source file entity, if any.
   *
   * @param string $uuid
   *   The media entity UUID stored on the link.
   *
   * @return \Drupal\file\FileInterface|null
   *   The referenced file, or NULL when the media or its file is unavailable.
   */
  protected function resolveMediaFile(string $uuid): ?FileInterface {
    if (array_key_exists($uuid, $this->mediaFileCache)) {
      return $this->mediaFileCache[$uuid];
    }

    $file = NULL;
    $media = $this->entityRepository->loadEntityByUuid('media', $uuid);
    if ($media instanceof MediaInterface) {
      $source_field = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
      if ($source_field && $media->hasField($source_field)) {
        $target = $media->get($source_field)->entity;
        $file = $target instanceof FileInterface ? $target : NULL;
      }
    }

    return $this->mediaFileCache[$uuid] = $file;
  }

  /**
   * Returns the text item properties to scan for a given field type.
   *
   * @param string $type
   *   The field type.
   *
   * @return string[]
   *   The property names holding markup.
   */
  protected function textProperties(string $type): array {
    return $type === 'text_with_summary' ? ['value', 'summary'] : ['value'];
  }

  /**
   * Gets the logger channel for this service.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   *   The logger channel.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get(self::LOGGER_CHANNEL);
  }

}
