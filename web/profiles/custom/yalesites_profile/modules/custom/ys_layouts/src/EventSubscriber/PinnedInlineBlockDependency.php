<?php

namespace Drupal\ys_layouts\EventSubscriber;

use Drupal\block_content\BlockContentEvents;
use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\EventSubscriber\SetInlineBlockDependency;

/**
 * Gives inline blocks pinned to an older revision an access dependency.
 *
 * Core only sets the layout entity as the dependency when the block revision
 * being checked is the one the layout uses. Child entities such as paragraphs
 * load their parent block at its default revision, so after a page is reverted
 * (the layout pins an older block revision) core finds no dependency and
 * denies access, and the block renders empty.
 *
 * This subscriber runs after core's and matches on the block ID instead of the
 * revision ID, so access still follows the layout entity's own access.
 */
class PinnedInlineBlockDependency extends SetInlineBlockDependency {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after core's subscriber, which handles the exact-revision case.
    return [
      BlockContentEvents::BLOCK_CONTENT_GET_DEPENDENCY => ['onGetDependency', -10],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function isBlockRevisionUsedInEntity(EntityInterface $layout_entity, BlockContentInterface $block_content) {
    $storage = $this->entityTypeManager->getStorage('block_content');
    $revision_ids = $this->getInlineBlockRevisionIdsInSections($this->getEntitySections($layout_entity));
    foreach ($revision_ids as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if ($revision && $revision->id() === $block_content->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
