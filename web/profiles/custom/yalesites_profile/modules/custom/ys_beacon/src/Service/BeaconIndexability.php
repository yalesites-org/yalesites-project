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
 * via the ai_disable_indexing metatag. Used by the ExcludeAiDisabled search
 * processor at indexing time and by the entity-update hook that removes
 * chunks immediately when content stops being indexable.
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
   * including token replacement, so behavior matches the legacy pipeline.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being checked.
   *
   * @return bool
   *   TRUE when the ai_disable_indexing metatag resolves to "disabled".
   */
  public function isIndexingDisabled(EntityInterface $entity): bool {
    $tags = $this->metatagManager->tagsFromEntityWithDefaults($entity);
    // Most content never sets the tag: skip token replacement entirely then.
    // It is by far the most expensive part of this check when run for every
    // indexed item.
    if (!isset($tags['ai_disable_indexing'])) {
      return FALSE;
    }
    // Token-replace only this tag. Metatag's per-entity token cache is primed
    // with whatever subset is passed first, which is harmless in the indexing
    // context.
    $metatags = $this->metatagManager->generateTokenValues(['ai_disable_indexing' => $tags['ai_disable_indexing']], $entity);
    return isset($metatags['ai_disable_indexing']) && $metatags['ai_disable_indexing'] == 'disabled';
  }

}
