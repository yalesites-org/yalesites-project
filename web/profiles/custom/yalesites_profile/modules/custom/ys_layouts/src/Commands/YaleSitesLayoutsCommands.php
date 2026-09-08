<?php

namespace Drupal\ys_layouts\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for YaleSites Layout Builder maintenance.
 */
class YaleSitesLayoutsCommands extends DrushCommands {

  /**
   * Constructs a YaleSitesLayoutsCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ys_layouts\Service\OrphanedInlineBlockCleanerInterface $cleaner
   *   The orphaned inline block cleaner.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OrphanedInlineBlockCleanerInterface $cleaner,
  ) {
    parent::__construct();
  }

  /**
   * Reports inline blocks that no Layout Builder layout references any more.
   *
   * Removing a non-reusable block from a page leaves its content record behind
   * with no admin screen able to reach it. Drupal core cannot clean these up on
   * a node that still exists, so this sweep does it deliberately. It reports by
   * default and only deletes when asked.
   *
   * @command ys-layouts:orphaned-blocks
   * @aliases ys-orphaned-blocks
   * @option delete Delete the reported orphans instead of only listing them.
   * @usage ys-layouts:orphaned-blocks
   *   List orphaned inline blocks. Changes nothing.
   * @usage ys-layouts:orphaned-blocks --delete
   *   Delete the orphaned inline blocks.
   */
  public function orphanedBlocks(array $options = ['delete' => FALSE]): void {
    // Deleting derives its own orphan list, so the two modes are kept separate
    // to avoid sweeping every revision on the site twice in one run. The
    // deleted IDs are written to the log by the service.
    if (!empty($options['delete'])) {
      $deleted = $this->cleaner->deleteOrphans();
      $this->logger->success($deleted
        ? sprintf('Deleted %d orphaned inline block(s).', $deleted)
        : 'No orphaned inline blocks found.');
      return;
    }

    $report = $this->cleaner->analyze();

    if ($report['revision_only']) {
      $this->logger->notice(sprintf(
        '%d inline block(s) are referenced only by older revisions and are NOT collected: %s. Rolling a node back to such a revision makes the block live again.',
        count($report['revision_only']),
        implode(', ', $report['revision_only'])
      ));
    }

    if (!$report['orphans']) {
      $this->logger->success('No orphaned inline blocks found.');
      return;
    }

    $this->io()->table(
      ['Block ID', 'Type', 'Label'],
      $this->describe($report['orphans'])
    );
    $this->logger->notice(sprintf(
      '%d orphaned inline block(s) found. Nothing was changed. Re-run with --delete to remove them.',
      count($report['orphans'])
    ));
  }

  /**
   * Builds table rows describing the given blocks.
   *
   * @param int[] $block_ids
   *   The block content entity IDs.
   *
   * @return array[]
   *   Rows of block ID, bundle and label.
   */
  protected function describe(array $block_ids): array {
    $blocks = $this->entityTypeManager->getStorage('block_content')->loadMultiple($block_ids);
    $rows = [];
    foreach ($block_ids as $block_id) {
      $block = $blocks[$block_id] ?? NULL;
      $rows[] = [
        $block_id,
        $block ? $block->bundle() : 'missing',
        $block ? (string) $block->label() : '',
      ];
    }
    return $rows;
  }

}
