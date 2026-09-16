<?php

namespace Drupal\ys_layouts\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\Section;

/**
 * Finds and deletes Layout Builder inline blocks no layout references.
 *
 * Removing a non-reusable block from a page's layout does not delete the
 * underlying block_content entity, and no admin screen can reach it afterwards.
 * Drupal core cannot clean these up for a node that still exists:
 * InlineBlockEntityOperations::removeUnusedForEntityOnSave() early-returns for
 * anything implementing RevisionableInterface, and layout_builder_cron() only
 * collects inline_block_usage rows whose parent columns are both NULL, which
 * happens only when the parent entity is deleted outright.
 *
 * Core skips the work because it cannot cheaply prove a block is unreferenced
 * from inside a single entity save. A deliberate sweep can afford that proof,
 * so this service does it properly: a block counts as referenced if ANY
 * revision of ANY layout-bearing entity points at ANY of its revisions, or if
 * a Layout Builder default layout in config points at it.
 *
 * See the module README for the operator-facing description and the full list
 * of safety guarantees.
 */
class OrphanedInlineBlockCleaner implements OrphanedInlineBlockCleanerInterface {

  /**
   * How many entities to load, or blocks to delete, at a time.
   */
  private const BATCH_SIZE = 50;

  /**
   * The field type Layout Builder stores layouts in.
   */
  private const LAYOUT_FIELD_TYPE = 'layout_section';

  /**
   * Plugin ID prefix shared by every inline block derivative.
   */
  private const INLINE_BLOCK_PREFIX = 'inline_block:';

  /**
   * Constructs an OrphanedInlineBlockCleaner.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager, used to discover layout fields.
   * @param \Drupal\layout_builder\InlineBlockUsageInterface $inlineBlockUsage
   *   Core's inline block usage tracker.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The ys_layouts logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected InlineBlockUsageInterface $inlineBlockUsage,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function analyze(): array {
    [$referenced_anywhere, $referenced_currently] = $this->collectReferencedRevisionIds();

    // One query yields the whole revision ID => block ID map, so narrowing to
    // the live subset is a key lookup rather than a second query.
    $map = $this->blockIdsByRevisionId($referenced_anywhere);
    $any = array_unique($map);
    $current = array_unique(array_intersect_key($map, array_flip($referenced_currently)));
    $candidates = $this->inlineBlockIds();

    $orphans = array_diff($candidates, $any);
    // Referenced somewhere, but never by a layout that is currently live.
    $revision_only = array_intersect($candidates, array_diff($any, $current));
    // sort() reindexes, so no array_values() is needed.
    sort($orphans);
    sort($revision_only);

    return [
      'orphans' => $orphans,
      'revision_only' => $revision_only,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteOrphans(): int {
    $orphans = $this->analyze()['orphans'];
    if (!$orphans) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('block_content');
    $deleted = 0;
    foreach (array_chunk($orphans, self::BATCH_SIZE) as $chunk) {
      $blocks = $storage->loadMultiple($chunk);
      if ($blocks) {
        $storage->delete($blocks);
        $deleted += count($blocks);
      }
    }

    // Clear the tracking rows too, matching what core does when it is able to
    // clean up itself. This also clears rows for blocks that were already gone.
    $this->inlineBlockUsage->deleteUsage($orphans);

    $this->logger->notice('Deleted @count orphaned inline block(s): @ids', [
      '@count' => $deleted,
      '@ids' => implode(', ', $orphans),
    ]);

    return $deleted;
  }

  /**
   * Collects inline block revision IDs referenced by any layout on the site.
   *
   * @return array
   *   A two-element list: the revision IDs referenced by any revision, and the
   *   subset referenced by a currently live layout (a default revision or a
   *   Layout Builder default in config).
   */
  protected function collectReferencedRevisionIds(): array {
    $anywhere = [];
    $currently = [];

    $field_map = $this->entityFieldManager->getFieldMapByFieldType(self::LAYOUT_FIELD_TYPE);
    foreach ($field_map as $entity_type_id => $fields) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$definition) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $field_names = array_keys($fields);

      // Test the entity type, not the storage class: SqlContentEntityStorage
      // implements RevisionableStorageInterface even for entity types that are
      // not revisionable, and calling allRevisions() on one of those throws
      // "No revision table for <type>, invalid query". Section Library's
      // section_library_template is a real example of a layout-bearing entity
      // type with no revision table.
      $revisionable = $definition->isRevisionable() && $storage instanceof RevisionableStorageInterface;

      $query = $storage->getQuery()->accessCheck(FALSE);
      if ($revisionable) {
        $query->allRevisions();
      }
      $result = $query->execute();
      // An allRevisions() query keys results by revision ID; a plain one keys
      // by entity ID.
      $keys = $revisionable ? array_keys($result) : array_values($result);

      foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
        $entities = $revisionable
          ? $storage->loadMultipleRevisions($chunk)
          : $storage->loadMultiple($chunk);
        foreach ($entities as $entity) {
          // A non-revisionable entity's only layout is by definition the live
          // one, so it can never be a revision-only reference.
          $is_live = !$revisionable || $entity->isDefaultRevision();
          foreach ($this->inlineBlockRevisionIds($this->sections($entity, $field_names)) as $id) {
            // Accumulate as keyed sets: array_merge inside this loop would copy
            // the growing array on every revision.
            $anywhere[$id] = TRUE;
            if ($is_live) {
              $currently[$id] = TRUE;
            }
          }
        }
      }
    }

    // Default layouts live in config rather than on any entity, so an
    // entity-only sweep would report the blocks they use as orphans.
    foreach ($this->entityTypeManager->getStorage('entity_view_display')->loadMultiple() as $display) {
      if (!$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
        continue;
      }
      foreach ($this->inlineBlockRevisionIds($display->getSections()) as $id) {
        $anywhere[$id] = TRUE;
        $currently[$id] = TRUE;
      }
    }

    return [array_keys($anywhere), array_keys($currently)];
  }

  /**
   * Gets the layout sections stored on an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity or entity revision to read.
   * @param string[] $field_names
   *   The layout field names to look at.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections found.
   */
  protected function sections(EntityInterface $entity, array $field_names): array {
    $sections = [];
    foreach ($field_names as $field_name) {
      // The field map is per entity type rather than per bundle, so a bundle
      // without the layout field can still reach here.
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $sections = array_merge($sections, $entity->get($field_name)->getSections());
    }
    return $sections;
  }

  /**
   * Extracts inline block revision IDs from sections.
   *
   * Reads the stored component configuration rather than instantiating each
   * block plugin. SectionComponent::getPlugin() would throw
   * PluginNotFoundException for a component whose block type has since been
   * deleted, which would abort a whole-site sweep.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to inspect.
   *
   * @return int[]
   *   The referenced block content revision IDs.
   */
  protected function inlineBlockRevisionIds(array $sections): array {
    $revision_ids = [];
    foreach ($sections as $section) {
      // LayoutSectionItemList::getSections() returns each item's raw section
      // property, which is NULL for an empty field item.
      if (!$section instanceof Section) {
        continue;
      }
      foreach ($section->getComponents() as $component) {
        $configuration = $component->get('configuration');
        if (!is_array($configuration)) {
          continue;
        }
        $plugin_id = $configuration['id'] ?? '';
        if (!is_string($plugin_id) || !str_starts_with($plugin_id, self::INLINE_BLOCK_PREFIX)) {
          continue;
        }
        if (!empty($configuration['block_revision_id'])) {
          $revision_ids[] = (int) $configuration['block_revision_id'];
        }
      }
    }
    return $revision_ids;
  }

  /**
   * Maps block content revision IDs to their owning entity IDs.
   *
   * @param int[] $revision_ids
   *   Block content revision IDs.
   *
   * @return int[]
   *   Block content entity IDs, keyed by the revision ID that resolved them.
   */
  protected function blockIdsByRevisionId(array $revision_ids): array {
    if (!$revision_ids) {
      return [];
    }
    // allRevisions() is required: a layout can reference a superseded block
    // revision, which a default-revision-only query would never match. It also
    // makes the query return revision ID => entity ID rather than just IDs.
    $result = $this->entityTypeManager->getStorage('block_content')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('revision_id', $revision_ids, 'IN')
      ->execute();

    return array_map('intval', $result);
  }

  /**
   * Gets every inline block candidate on the site.
   *
   * Layout Builder creates inline blocks with reusable set to FALSE, which is
   * what separates them from the reusable Custom Block Library.
   *
   * @return int[]
   *   The candidate block content entity IDs.
   */
  protected function inlineBlockIds(): array {
    $result = $this->entityTypeManager->getStorage('block_content')->getQuery()
      ->accessCheck(FALSE)
      ->condition('reusable', 0)
      ->execute();

    return array_values(array_map('intval', $result));
  }

}
