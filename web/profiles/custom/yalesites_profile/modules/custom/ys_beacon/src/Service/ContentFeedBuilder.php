<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\media\MediaInterface;

/**
 * Builds the AI content feed: a paginated, structured list of indexed content.
 *
 * Equivalent of the legacy ai_engine_feed `/api/ai/v1/content` endpoint, but
 * filtered by exactly the same indexability rules the push-based pipeline uses
 * (BeaconIndexability), so a pull consumer sees the same content the chatbot
 * indexes - published, anonymously viewable, and not opted out via the
 * ai_disable_indexing metatag.
 */
class ContentFeedBuilder {

  /**
   * Entity types the feed can serve, mapped to their published-status field.
   */
  protected const SUPPORTED_TYPES = [
    'node' => 'status',
    'media' => 'status',
  ];

  /**
   * The default and maximum number of entities scanned per page.
   */
  public const DEFAULT_PAGE_SIZE = 50;
  public const MAX_PAGE_SIZE = 200;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BeaconIndexability $indexability,
    protected AiMetadataManager $aiMetadataManager,
    protected RendererInterface $renderer,
    protected AccountSwitcherInterface $accountSwitcher,
  ) {
  }

  /**
   * Builds one page of the content feed.
   *
   * @param string $type
   *   The entity type to feed ('node' or 'media').
   * @param int $page
   *   The 1-based page number.
   * @param int $pageSize
   *   The page window size (clamped to MAX_PAGE_SIZE).
   *
   * @return array
   *   The feed payload: data items, pagination, and totals.
   *
   * @throws \InvalidArgumentException
   *   When the entity type is not supported.
   */
  public function build(string $type, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): array {
    if (!isset(self::SUPPORTED_TYPES[$type])) {
      throw new \InvalidArgumentException(sprintf('Unsupported feed type "%s".', $type));
    }
    $page = max(1, $page);
    $pageSize = max(1, min($pageSize, self::MAX_PAGE_SIZE));
    $status_field = self::SUPPORTED_TYPES[$type];
    $storage = $this->entityTypeManager->getStorage($type);
    $id_key = $this->entityTypeManager->getDefinition($type)->getKey('id');

    $total = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($status_field, 1)
      ->count()
      ->execute();

    // Page over published entities by id; the per-item indexability filter
    // (anonymous view access, ai_disable_indexing) then removes any that must
    // not be exposed, so a page may yield fewer than $pageSize items.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($status_field, 1)
      ->sort($id_key)
      ->range(($page - 1) * $pageSize, $pageSize)
      ->execute();

    $data = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof ContentEntityInterface && $this->indexability->isIndexable($entity)) {
        $data[] = $this->buildItem($entity);
      }
    }

    return [
      'data' => $data,
      'pagination' => [
        'type' => $type,
        'page' => $page,
        'page_size' => $pageSize,
        'total_records' => $total,
        'total_pages' => (int) ceil($total / $pageSize),
      ],
    ];
  }

  /**
   * Builds the structured feed item for one entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The indexable entity.
   *
   * @return array
   *   The structured item.
   */
  public function buildItem(ContentEntityInterface $entity): array {
    $ai = $this->aiMetadataManager->getAiMetadata($entity);
    $created = $entity->hasField('created') ? (int) $entity->get('created')->value : 0;
    $changed = method_exists($entity, 'getChangedTime') ? (int) $entity->getChangedTime() : 0;

    return [
      'id' => $entity->getEntityTypeId() . '/' . $entity->id(),
      'type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'uuid' => $entity->uuid(),
      'title' => (string) $entity->label(),
      'url' => $this->itemUrl($entity),
      'langcode' => $entity->language()->getId(),
      'created' => $created ? gmdate('c', $created) : NULL,
      'changed' => $changed ? gmdate('c', $changed) : NULL,
      'ai_description' => $ai['ai_description'] ?? '',
      'ai_tags' => $ai['ai_tags'] ?? '',
      'content' => $entity instanceof MediaInterface ? '' : $this->renderContent($entity),
    ];
  }

  /**
   * The canonical URL, or the source file URL for media.
   */
  protected function itemUrl(ContentEntityInterface $entity): ?string {
    try {
      if ($entity instanceof MediaInterface) {
        $fid = $entity->getSource()->getSourceFieldValue($entity);
        if ($fid && is_numeric($fid)) {
          $file = $this->entityTypeManager->getStorage('file')->load($fid);
          if ($file) {
            return $file->createFileUrl(FALSE);
          }
        }
      }
      if ($entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
    }
    catch (\Throwable $e) {
      // A missing link is acceptable; the item is still useful.
    }
    return NULL;
  }

  /**
   * Renders an entity's default view as anonymous, returning plain text.
   *
   * Rendered as the anonymous user so the feed body matches what the chatbot
   * indexes and never leaks content only privileged users can see. Isolation
   * keeps the render's cache metadata out of the JSON response.
   */
  protected function renderContent(ContentEntityInterface $entity): string {
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $build = $this->entityTypeManager
        ->getViewBuilder($entity->getEntityTypeId())
        ->view($entity, 'default');
      $html = (string) $this->renderer->renderInIsolation($build);
    }
    catch (\Throwable $e) {
      $html = '';
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
  }

}
