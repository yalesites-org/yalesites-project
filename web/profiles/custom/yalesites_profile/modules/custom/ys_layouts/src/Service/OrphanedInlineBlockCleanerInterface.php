<?php

namespace Drupal\ys_layouts\Service;

/**
 * Finds and deletes Layout Builder inline blocks no layout references.
 */
interface OrphanedInlineBlockCleanerInterface {

  /**
   * Analyses every layout on the site for unreferenced inline blocks.
   *
   * @return array
   *   An associative array with two keys, each a sorted list of block_content
   *   entity IDs:
   *   - orphans: referenced by no layout at all. Safe to delete.
   *   - revision_only: referenced only by a non-default entity revision, never
   *     by a current layout. Reported for review but never deleted, because
   *     rolling that revision back would make the block live again.
   */
  public function analyze(): array;

  /**
   * Deletes every orphan found by a fresh analysis.
   *
   * This deliberately takes no ID list. Deriving the orphans here is what makes
   * deleting a block that is still on a page structurally impossible, rather
   * than something a caller has to be trusted not to ask for.
   *
   * @return int
   *   The number of blocks deleted.
   */
  public function deleteOrphans(): int;

}
