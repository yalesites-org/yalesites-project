<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\metatag\MetatagManagerInterface;

/**
 * Decides whether an entity belongs in the Beacon vector index.
 *
 * Single home for the indexability rule, mirroring the legacy
 * ai_engine_embedding pipeline: content must be published, viewable by
 * anonymous visitors (the chat serves anonymous traffic), and not opted out
 * via the ai_disable_indexing metatag. Media (PDFs, documents, images) is the
 * exception: it is excluded by default and must be explicitly opted in. Used
 * by the ExcludeAiDisabled search processor at indexing time and by the
 * entity-update hook that removes chunks immediately when content stops being
 * indexable.
 */
class BeaconIndexability {

  public function __construct(
    protected MetatagManagerInterface $metatagManager,
  ) {
  }

  /**
   * Checks whether an entity may be indexed for AI retrieval.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE when the entity is published, anonymously viewable, and not
   *   excluded via the ai_disable_indexing metatag.
   */
  public function isIndexable(EntityInterface $entity): bool {
    if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
      return FALSE;
    }
    // The chat answers anonymous visitors: content their session cannot view
    // (CAS-protected pages, node access restrictions) must never be indexed.
    if (!$entity->access('view', new AnonymousUserSession())) {
      return FALSE;
    }
    return !$this->isIndexingDisabled($entity);
  }

  /**
   * Checks whether AI indexing is disabled for an entity via metatag.
   *
   * Mirrors EntityUpdate::isIndexingEnabled() from ai_engine_embedding,
   * including token replacement, so behavior matches the legacy pipeline -
   * except that media defaults to excluded rather than included.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being checked.
   *
   * @return bool
   *   TRUE when the content is excluded from the AI index: the
   *   ai_disable_indexing metatag resolves to "disabled", or the entity is
   *   media that has not been explicitly opted in.
   */
  public function isIndexingDisabled(EntityInterface $entity): bool {
    $tags = $this->metatagManager->tagsFromEntityWithDefaults($entity);
    // When the tag was never set, media (PDFs, documents, images) is excluded
    // by default: an editor must opt a media item in by unchecking "Disable
    // indexing for AI feeds", which stores an explicit "enabled" value. Other
    // entity types are included by default. This also skips the expensive
    // token replacement below for the common unset case.
    if (!isset($tags['ai_disable_indexing']) || $tags['ai_disable_indexing'] === '') {
      return $entity->getEntityTypeId() === 'media';
    }
    // Token-replace only this tag. Metatag's per-entity token cache is primed
    // with whatever subset is passed first, which is harmless in the indexing
    // context.
    $metatags = $this->metatagManager->generateTokenValues(['ai_disable_indexing' => $tags['ai_disable_indexing']], $entity);
    return isset($metatags['ai_disable_indexing']) && $metatags['ai_disable_indexing'] == 'disabled';
  }

}
