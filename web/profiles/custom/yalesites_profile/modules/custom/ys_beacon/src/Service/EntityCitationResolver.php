<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;

/**
 * Derives the citation title and URL for a content entity.
 *
 * Shared by RagRetriever (query time, for the owning site's own content) and
 * the CitationFields search processor (index time, so borrowing sites can cite
 * from stored fields). Keeping the derivation in one place guarantees a
 * document cited from stored fields links to the same place it would when
 * cited via a live entity load.
 */
class EntityCitationResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Builds the citation title for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to title.
   *
   * @return string
   *   The entity label (node title or media name).
   */
  public function title(ContentEntityInterface $entity): string {
    return (string) $entity->label();
  }

  /**
   * Builds the citation URL for an entity.
   *
   * Media items link directly to their source file when possible, matching the
   * legacy ai_engine feed behavior; everything else uses the canonical entity
   * URL.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to link to.
   *
   * @return string|null
   *   An absolute URL, or NULL when none can be generated.
   */
  public function url(ContentEntityInterface $entity): ?string {
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
      // Fall through to NULL: a citation without a link is still usable.
    }
    return NULL;
  }

}
